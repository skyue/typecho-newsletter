<?php

use Typecho\Common;
use Typecho\Db;
use Typecho\Http\Client;

class Newsletter_Service
{
    /**
     * 通过 AWS SES API v2 发送邮件
     */
    public static function send(
        string $accessKeyId,
        string $secretAccessKey,
        string $region,
        string $from,
        string $to,
        string $subject,
        string $html,
        string $replyTo = '',
        string $fromName = ''
    ): array {
        $client = Client::get();
        if (!$client) {
            return ['success' => false, 'message' => 'cURL 不可用'];
        }

        $fromAddress = $from;
        if (!empty($fromName)) {
            $fromAddress = '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>';
        }

        $host = "email.{$region}.amazonaws.com";
        $url  = "https://{$host}/v2/email/outbound-emails";
        $service = 'ses';

        $payloadArr = [
            'FromEmailAddress' => $fromAddress,
            'Destination' => [
                'ToAddresses' => [$to],
            ],
            'Content' => [
                'Simple' => [
                    'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
                    'Body'    => ['Html' => ['Data' => $html, 'Charset' => 'UTF-8']],
                ],
            ],
        ];
        if (!empty($replyTo)) {
            $payloadArr['ReplyToAddresses'] = [$replyTo];
        }
        $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $now = time();
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $dateStamp = gmdate('Ymd', $now);

        // ---- AWS Signature V4 ----

        // 1. Canonical request
        $canonicalHeaders = "content-type:application/json\nhost:{$host}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'content-type;host;x-amz-date';
        $payloadHash = hash('sha256', $payload);

        $canonicalRequest = "POST\n/v2/email/outbound-emails\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        // 2. String to sign
        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        // 3. Signing key
        $kDate   = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretAccessKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        // 4. Authorization header
        $authHeader = "AWS4-HMAC-SHA256 Credential={$accessKeyId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        // ---- Send ----
        try {
            $client->setData($payload, 'POST')
                ->setHeader('Content-Type', 'application/json')
                ->setHeader('Host', $host)
                ->setHeader('X-Amz-Date', $amzDate)
                ->setHeader('Authorization', $authHeader)
                ->setTimeout(15)
                ->send($url);

            $status = $client->getResponseStatus();
            if ($status >= 200 && $status < 300) {
                return ['success' => true, 'message' => 'OK'];
            }

            $body = $client->getResponseBody();
            $msg = json_decode($body, true)['message'] ?? $body;
            return ['success' => false, 'message' => "HTTP {$status}: {$msg}"];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 发送即时推送（新文章发布后）
     */
    public static function sendInstant(array $contents, $widget): void
    {
        $options = \Widget\Options::alloc();
        try {
            $config = $options->plugin('Newsletter');
        } catch (\Exception $e) {
            return;
        }

        $accessKey    = $config->accessKeyId ?? '';
        $secretKey    = $config->secretAccessKey ?? '';
        $region       = $config->region ?? 'us-east-1';
        $fromEmail    = $config->fromEmail ?? '';
        $replyTo      = $config->replyTo ?? '';
        $fromName     = $config->fromName ?? $options->title;
        $contentMode  = $config->contentMode ?? 'full';

        if (empty($accessKey) || empty($secretKey) || empty($fromEmail)) {
            return;
        }

        $cid = (string)($widget->cid ?? 0);

        // 去重：已发送过的文章不再发送
        $db = Db::get();
        $existing = $db->fetchRow(
            $db->select()->from('table.newsletter_digest_log')
                ->where('post_ids = ?', $cid)
                ->orWhere('post_ids LIKE ?', $cid . ',%')
                ->orWhere('post_ids LIKE ?', '%,' . $cid . ',%')
                ->orWhere('post_ids LIKE ?', '%,' . $cid)
                ->limit(1)
        );
        if ($existing) {
            return;
        }

        // 分类过滤
        $filterCategory = $config->category ?? '';
        if (!empty($filterCategory)) {
            $postCategories = $contents['category'] ?? [];
            if (!in_array($filterCategory, $postCategories)) {
                return;
            }
        }

        $title = $contents['title'] ?? '';
        $text  = $contents['text'] ?? '';
        $permalink = $widget->permalink ?: $options->siteUrl;

        $html = self::buildInstantHtml($contentMode, $title, $text, $permalink);

        $db = Db::get();
        $subscribers = $db->fetchAll(
            $db->select('email')->from('table.newsletter_subscribers')->where('status = ?', 'active')
        );

        $sent = 0;
        foreach ($subscribers as $sub) {
            $unsubscribeUrl = Common::url(
                '/action/newsletter-unsubscribe?token=' . self::getToken($sub['email']),
                $options->siteUrl
            );
            $htmlWithUnsub = str_replace('{{unsubscribeUrl}}', $unsubscribeUrl, $html);
            $result = self::send($accessKey, $secretKey, $region, $fromEmail, $sub['email'],
                "[{$options->title}] {$title}", $htmlWithUnsub, $replyTo, $fromName);
            if ($result['success']) {
                $sent++;
            }
        }

        // 记录发送日志
        $db->query(
            $db->insert('table.newsletter_digest_log')->rows([
                'post_ids'         => (string)($widget->cid ?? 0),
                'subscriber_count' => $sent,
                'sent_at'          => time(),
            ])
        );
    }

    /**
     * 构建即时推送邮件 HTML
     */
    public static function buildInstantHtml(string $contentMode, string $title, string $text, string $permalink): string
    {
        $html = \Utils\Markdown::convert($text);
        if ($contentMode === 'excerpt') {
            [$body] = explode('<!--more-->', $html);
            $body = Common::fixHtml($body);
        } else {
            $body = $html;
        }

        $template = self::getTemplate('instant');
        return str_replace(
            ['{{siteTitle}}', '{{postTitle}}', '{{postContent}}', '{{postUrl}}', '{{unsubscribeUrl}}'],
            [\Widget\Options::alloc()->title, $title, $body, $permalink, '{{unsubscribeUrl}}'],
            $template
        );
    }

    public static function getTemplate(string $name): string
    {
        $file = __DIR__ . '/templates/' . $name . '.php';
        if (file_exists($file)) {
            return file_get_contents($file);
        }
        return self::defaultTemplate($name);
    }

    public static function getToken(string $email): string
    {
        $db = Db::get();
        $row = $db->fetchRow(
            $db->select('token')->from('table.newsletter_subscribers')->where('email = ?', $email)
        );
        return $row['token'] ?? '';
    }

    private static function defaultTemplate(string $name): string
    {
        switch ($name) {
            case 'confirm':
                return '<div style="max-width:600px;margin:0 auto;font-family:sans-serif"><h2>确认订阅 {{siteTitle}}</h2><p>请点击下方链接确认订阅：</p><p><a href="{{confirmUrl}}">确认订阅</a></p><p>如果您没有请求此操作，请忽略此邮件。</p></div>';
            case 'instant':
                return '<div style="max-width:600px;margin:0 auto;font-family:sans-serif"><h2>{{postTitle}}</h2><div style="word-wrap:break-word;overflow-wrap:break-word">{{postContent}}</div><p><a href="{{postUrl}}">阅读原文</a></p><p style="color:#999;font-size:12px;margin-top:24px"><a href="{{unsubscribeUrl}}">退订</a></p></div>';
            default:
                return '';
        }
    }
}
