<?php

use Typecho\Common;
use Typecho\Db;
use Typecho\Widget;

class Newsletter_Action extends Widget implements \Widget\ActionInterface
{
    private $_db;
    private $_options;
    private $_config;

    public function init()
    {
        $this->_db = Db::get();
        $this->_options = \Widget\Options::alloc();
        try {
            $this->_config = $this->_options->plugin('Newsletter');
        } catch (\Exception $e) {
            $this->_config = null;
        }
    }

    public function action()
    {
        if ($this->request->isPost()) {
            $this->subscribe();
        } elseif ($this->request->get('token')) {
            $pathInfo = $this->request->getPathInfo();
            if (strpos($pathInfo, 'unsubscribe') !== false) {
                $this->unsubscribe();
            } else {
                $this->confirm();
            }
        }
    }

    // ---- subscribe ----

    private function subscribe()
    {
        if (!$this->request->isPost()) {
            $this->response->redirect($this->_options->siteUrl);
            return;
        }

        $subscribeNotes = [];
        $fromEmail = $this->_config->fromEmail ?? '';
        $subscribeNotes[] = _t('若收件箱找不到邮件，有可能被识别为垃圾邮件，到「垃圾邮件」中寻找');
        if (!empty($fromEmail)) {
            $subscribeNotes[] = sprintf(_t('建议将 %s 添加到邮件白名单，防止后续邮件被识别为垃圾邮件'), $fromEmail);
        }

        // 蜜罐检查：机器人自动填充隐藏字段
        if (trim($this->request->get('website', '')) !== '') {
            $this->showMessage(_t('确认邮件已发送，请检查您的邮箱，点击其中的确认链接完成订阅'), 'success', null, $subscribeNotes);
            return;
        }

        // 时间检查：表单渲染后至少 3 秒，且不超过 1 小时
        $ts = (int) $this->request->get('ts', 0);
        if ($ts === 0 || (time() - $ts) < 3 || (time() - $ts) > 3600) {
            $this->showMessage(_t('确认邮件已发送，请检查您的邮箱，点击其中的确认链接完成订阅'), 'success', null, $subscribeNotes);
            return;
        }

        $email = trim($this->request->get('email', ''));
        if (!\Typecho\Validate::email($email)) {
            $this->showMessage(_t('邮箱地址无效'), 'error');
            return;
        }

        $existing = $this->_db->fetchRow(
            $this->_db->select()->from('table.newsletter_subscribers')->where('email = ?', $email)
        );

        if ($existing) {
            if ($existing['status'] === 'active') {
                $this->showMessage(_t('您已经订阅过了'), 'info');
                return;
            }
            if ($existing['status'] === 'pending') {
                $token = $existing['token'];
            } else {
                // unsubscribed — 重新激活
                $token = $existing['token'];
                $this->_db->query(
                    $this->_db->update('table.newsletter_subscribers')
                        ->rows(['status' => 'pending', 'created_at' => time()])
                        ->where('email = ?', $email)
                );
            }
        } else {
            $token = Common::randString(32);
            $this->_db->query(
                $this->_db->insert('table.newsletter_subscribers')->rows([
                    'email'      => $email,
                    'status'     => 'pending',
                    'token'      => $token,
                    'created_at' => time(),
                ])
            );
        }

        // 发送确认邮件
        $confirmUrl = Common::url(
            '/action/newsletter-confirm?token=' . $token,
            $this->_options->siteUrl
        );

        $html = Newsletter_Service::getTemplate('confirm');
        $html = str_replace(
            ['{{siteTitle}}', '{{confirmUrl}}'],
            [$this->_options->title, $confirmUrl],
            $html
        );

        if ($this->_config && !empty($this->_config->accessKeyId) && !empty($this->_config->secretAccessKey) && !empty($this->_config->fromEmail)) {
            $fromName = $this->_config->fromName ?? $this->_options->title;
            Newsletter_Service::send(
                $this->_config->accessKeyId,
                $this->_config->secretAccessKey,
                $this->_config->region ?? 'us-east-1',
                $this->_config->fromEmail,
                $email,
                _t('确认订阅') . ' - ' . $this->_options->title,
                $html,
                $this->_config->replyTo ?? '',
                $fromName
            );
        }

        $this->showMessage(_t('确认邮件已发送，请检查您的邮箱，点击其中的确认链接完成订阅'), 'success', null, $subscribeNotes);
    }

    // ---- confirm ----

    private function confirm()
    {
        $token = $this->request->get('token', '');

        if (empty($token)) {
            $this->showMessage(_t('无效的确认链接'), 'error');
            return;
        }

        $sub = $this->_db->fetchRow(
            $this->_db->select()->from('table.newsletter_subscribers')->where('token = ?', $token)
        );

        if (!$sub) {
            $msg = _t('该订阅记录不存在，可能因长期未确认被管理员移除，请前往博客重新订阅并确认。');
            $siteUrl = $this->_options->siteUrl;
            $this->showMessage($msg, 'error', $siteUrl);
            return;
        }

        if ($sub['status'] === 'active') {
            $this->showMessage(_t('您已经确认过订阅了'), 'info', $this->_options->siteUrl);
            return;
        }

        $this->_db->query(
            $this->_db->update('table.newsletter_subscribers')
                ->rows(['status' => 'active', 'confirmed_at' => time()])
                ->where('token = ?', $token)
        );

        $this->showMessage(_t('订阅成功！您将收到博客更新通知'), 'success');
    }

    // ---- unsubscribe ----

    private function unsubscribe()
    {
        $token = $this->request->get('token', '');

        if (empty($token)) {
            $this->showMessage(_t('无效的退订链接'), 'error');
            return;
        }

        $sub = $this->_db->fetchRow(
            $this->_db->select()->from('table.newsletter_subscribers')->where('token = ?', $token)
        );

        if (!$sub) {
            $this->showMessage(_t('无效的退订链接'), 'error');
            return;
        }

        if ($sub['status'] === 'unsubscribed') {
            $this->showMessage(_t('您已经退订了'), 'info');
            return;
        }

        $this->_db->query(
            $this->_db->update('table.newsletter_subscribers')
                ->rows(['status' => 'unsubscribed'])
                ->where('token = ?', $token)
        );

        $this->showMessage(_t('您已成功退订，不会再收到邮件'), 'success');
    }

    private function showMessage(string $msg, string $type = 'info', string $siteUrl = null, array $notes = [])
    {
        if ($siteUrl === null) {
            $siteUrl = $this->_options->siteUrl;
        }
        $color = $type === 'error' ? '#c00' : ($type === 'success' ? '#2a7d2a' : '#444');
        $notesHtml = '';
        foreach ($notes as $note) {
            $notesHtml .= '<p>' . htmlspecialchars($note) . '</p>';
        }
        echo '<!DOCTYPE html><html lang="zh"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->_options->title . '</title><style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f6f6f3}.msg{text-align:center;padding:40px 20px;max-width:400px}.msg h2{color:' . $color . ';font-size:1.2em;margin:0 0 8px}.msg p{color:#666;margin:0 0 16px}.msg a{color:#467b96}</style></head><body><div class="msg"><h2>' . htmlspecialchars($msg) . '</h2>' . $notesHtml . '<p><a href="' . $siteUrl . '">' . _t('返回首页') . '</a></p></div></body></html>';
        exit;
    }
}
