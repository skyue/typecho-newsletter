<?php

class Newsletter_Console extends Typecho_Widget
{
    private $_db;
    private $_dir;

    public function execute()
    {
        \Widget\User::alloc()->pass('administrator');
        $this->_db = Typecho\Db::get();
        $this->_dir = __DIR__;
    }

    /**
     * 获取订阅者列表
     */
    public function getSubscribers(): array
    {
        return $this->_db->fetchAll(
            $this->_db->select()->from('table.newsletter_subscribers')->order('created_at', Typecho\Db::SORT_DESC)
        );
    }

    /**
     * 获取摘要发送日志
     */
    public function getDigestLogs(): array
    {
        return $this->_db->fetchAll(
            $this->_db->select()->from('table.newsletter_digest_log')->order('sent_at', Typecho\Db::SORT_DESC)->limit(50)
        );
    }

    /**
     * 删除订阅者
     */
    public function deleteSubscriber($id)
    {
        $this->_db->query(
            $this->_db->delete('table.newsletter_subscribers')->where('id = ?', $id)
        );
    }

    /**
     * 手动添加订阅者
     */
    public function addSubscriber(string $email): string
    {
        if (!\Typecho\Validate::email($email)) {
            return _t('邮箱地址无效');
        }

        $existing = $this->_db->fetchRow(
            $this->_db->select()->from('table.newsletter_subscribers')->where('email = ?', $email)
        );

        if ($existing) {
            if ($existing['status'] === 'active') {
                return _t('该邮箱已订阅');
            }
            // 重新激活
            $this->_db->query(
                $this->_db->update('table.newsletter_subscribers')
                    ->rows(['status' => 'active', 'confirmed_at' => time()])
                    ->where('email = ?', $email)
            );
            return '';
        }

        $token = \Typecho\Common::randString(32);
        $this->_db->query(
            $this->_db->insert('table.newsletter_subscribers')->rows([
                'email'        => $email,
                'status'       => 'active',
                'token'        => $token,
                'created_at'   => time(),
                'confirmed_at' => time(),
            ])
        );

        return '';
    }

    /**
     * 发送测试邮件 — 使用插件真实配置模拟发送
     */
    public function sendTest(string $to): array
    {
        $options = \Widget\Options::alloc();
        try {
            $config = $options->plugin('Newsletter');
        } catch (\Exception $e) {
            return ['success' => false, 'message' => '插件配置未找到'];
        }

        $accessKey   = $config->accessKeyId ?? '';
        $secretKey   = $config->secretAccessKey ?? '';
        $region      = $config->region ?? 'us-east-1';
        $fromEmail   = $config->fromEmail ?? '';
        $replyTo     = $config->replyTo ?? '';
        $fromName    = $config->fromName ?? $options->title;
        $contentMode = $config->contentMode ?? 'full';

        if (empty($accessKey) || empty($secretKey) || empty($fromEmail)) {
            return ['success' => false, 'message' => '未配置 AWS 凭证或发件地址'];
        }

        $db = \Typecho\Db::get();
        $post = $db->fetchRow(
            $db->select()->from('table.contents')
                ->where('type = ?', 'post')
                ->where('status = ?', 'publish')
                ->order('created', \Typecho\Db::SORT_DESC)
                ->limit(1)
        );

        if (empty($post)) {
            return ['success' => false, 'message' => '没有已发布的文章'];
        }

        $url = \Typecho\Router::url(
            'post',
            ['cid' => $post['cid'], 'slug' => $post['slug']],
            $options->index
        );
        $html = \Newsletter_Service::buildInstantHtml($contentMode, $post['title'], $post['text'], $url);
        $html = str_replace('{{unsubscribeUrl}}', '#', $html);
        $subject = "[{$options->title}] {$post['title']}";

        $result = \Newsletter_Service::send(
            $accessKey, $secretKey, $region, $fromEmail,
            $to, $subject, $html, $replyTo, $fromName
        );

        if ($result['success']) {
            return ['success' => true, 'message' => '测试邮件发送成功'];
        }

        return ['success' => false, 'message' => '发送失败：' . $result['message']];
    }

    /**
     * 保存邮件模板
     */
    public function saveTemplate(string $name, string $content, string $ext = 'php'): bool
    {
        $file = $this->_dir . '/templates/' . $name . '.' . $ext;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return file_put_contents($file, $content) !== false;
    }

    /**
     * 获取模板内容
     */
    public function getTemplate(string $name): string
    {
        return Newsletter_Service::getTemplate($name);
    }

}
