<?php
/**
 * Newsletter 邮件订阅插件
 * 文章发布时即时推送给所有订阅者，通过 AWS SES 发送邮件。
 *
 * @package Newsletter
 * @author skyue
 * @version 1.1.0
 * @link https://www.skyue.com
 * @since 1.3.0
 */
class Newsletter_Plugin implements Typecho_Plugin_Interface
{
    public static $action = 'newsletter';

    /**
     * 激活插件
     */
    public static function activate()
    {
        $db = Typecho\Db::get();
        $prefix = $db->getPrefix();
        $isSQLite = $db->getAdapterName() === 'Pdo_SQLite';

        $subscribersTable = $isSQLite
            ? "CREATE TABLE IF NOT EXISTS {$prefix}newsletter_subscribers (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                email       VARCHAR(255) NOT NULL UNIQUE,
                status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
                token       VARCHAR(64)  NOT NULL,
                created_at  INTEGER      NOT NULL,
                confirmed_at INTEGER     DEFAULT NULL
            )"
            : "CREATE TABLE IF NOT EXISTS {$prefix}newsletter_subscribers (
                id          INTEGER PRIMARY KEY AUTO_INCREMENT,
                email       VARCHAR(255) NOT NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
                token       VARCHAR(64)  NOT NULL,
                created_at  INTEGER      NOT NULL,
                confirmed_at INTEGER     DEFAULT NULL,
                UNIQUE (email)
            )";
        $db->query($subscribersTable);

        // 注册 Action 路由
        Helper::addAction('newsletter-subscribe', 'Newsletter_Action');
        Helper::addAction('newsletter-confirm', 'Newsletter_Action');
        Helper::addAction('newsletter-unsubscribe', 'Newsletter_Action');

        // 注册后台面板
        Helper::addPanel(1, 'Newsletter/page/console.php', 'Newsletter', 'Newsletter 控制台', 'administrator');

        // 即时推送 hook
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = ['Newsletter_Plugin', 'onPublish'];

        // 加载 Widget，用户可在主题中直接调用
        Typecho_Plugin::factory('index.php')->begin = ['Newsletter_Plugin', 'loadWidget'];
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        Helper::removeAction('newsletter-subscribe');
        Helper::removeAction('newsletter-confirm');
        Helper::removeAction('newsletter-unsubscribe');
        Helper::removePanel(1, 'Newsletter/page/console.php');
    }

    /**
     * 配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $accessKeyId = new Typecho_Widget_Helper_Form_Element_Text(
            'accessKeyId', null, null, _t('AWS Access Key ID'),
            _t('在 <a href="https://console.aws.amazon.com/iam" target="_blank">AWS IAM</a> 创建访问密钥')
        );
        $form->addInput($accessKeyId->addRule('required', _t('Access Key ID 不能为空')));

        $secretAccessKey = new Typecho_Widget_Helper_Form_Element_Password(
            'secretAccessKey', null, null, _t('AWS Secret Access Key')
        );
        $form->addInput($secretAccessKey->addRule('required', _t('Secret Access Key 不能为空')));

        $region = new Typecho_Widget_Helper_Form_Element_Text(
            'region', null, 'us-east-1', _t('AWS 区域'),
            _t('如 us-east-1, ap-southeast-1 等，需与 SES 配置一致')
        );
        $form->addInput($region->addRule('required', _t('区域不能为空')));

        $fromEmail = new Typecho_Widget_Helper_Form_Element_Text(
            'fromEmail', null, null, _t('发件邮箱地址'),
            _t('需在 AWS SES 中验证过的邮箱或域名，如 newsletter@yourdomain.com')
        );
        $form->addInput($fromEmail->addRule('required', _t('发件邮箱不能为空'))
            ->addRule('email', _t('请输入有效的邮箱地址')));

        $replyTo = new Typecho_Widget_Helper_Form_Element_Text(
            'replyTo', null, null, _t('回复邮箱地址'),
            _t('收件人回复时使用的邮箱地址，留空则使用发件邮箱')
        );
        $form->addInput($replyTo);

        $fromName = new Typecho_Widget_Helper_Form_Element_Text(
            'fromName', null, null, _t('发件人名称'),
            _t('显示在收件人邮件中的发件人名称，留空则使用博客名称')
        );
        $form->addInput($fromName);

        $db = Typecho\Db::get();
        $categories = $db->fetchAll(
            $db->select('mid', 'name')->from('table.metas')
                ->where('type = ?', 'category')
                ->order('order', Typecho\Db::SORT_ASC)
        );
        $categoryOptions = ['' => _t('不限制（所有分类）')];
        foreach ($categories as $cat) {
            $categoryOptions[$cat['mid']] = $cat['name'];
        }
        $category = new Typecho_Widget_Helper_Form_Element_Select(
            'category', $categoryOptions, '', _t('文章分类'),
            _t('仅发送指定分类下的文章更新，默认为所有分类')
        );
        $form->addInput($category);

        $contentMode = new Typecho_Widget_Helper_Form_Element_Radio(
            'contentMode',
            ['full' => _t('全文'), 'excerpt' => _t('摘要 + 链接')],
            'full',
            _t('邮件内容模式'),
            _t('选择邮件中包含文章全文，还是摘要 + 阅读原文链接')
        );
        $form->addInput($contentMode);

        $formTitle = new Typecho_Widget_Helper_Form_Element_Text(
            'formTitle', null, '邮件订阅', _t('弹窗标题'),
            _t('订阅弹窗的标题文字')
        );
        $form->addInput($formTitle);

        $formDesc = new Typecho_Widget_Helper_Form_Element_Text(
            'formDesc', null, '不想错过新文章？输入邮箱即可在发布时收到邮件通知。', _t('邮件订阅描述'),
            _t('邮件订阅区域的提示文字')
        );
        $form->addInput($formDesc);

        $formPlaceholder = new Typecho_Widget_Helper_Form_Element_Text(
            'formPlaceholder', null, 'your@email.com', _t('输入框占位文字'),
            _t('邮箱输入框的 placeholder')
        );
        $form->addInput($formPlaceholder);

        $formButton = new Typecho_Widget_Helper_Form_Element_Text(
            'formButton', null, '订阅', _t('按钮文字'),
            _t('订阅按钮显示的文字')
        );
        $form->addInput($formButton);

        $showRss = new Typecho_Widget_Helper_Form_Element_Radio(
            'showRss',
            ['1' => _t('显示'), '0' => _t('隐藏')],
            '0',
            _t('弹窗显示 RSS 订阅'),
            _t('在订阅弹窗中同时展示 RSS 链接，方便读者通过 RSS 阅读器订阅')
        );
        $form->addInput($showRss);

        $rssUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'rssUrl', null, \Widget\Options::alloc()->feedUrl, _t('RSS 地址'),
            _t('RSS 订阅链接，默认为博客的 RSS feed 地址')
        );
        $form->addInput($rssUrl);

        $rssDesc = new Typecho_Widget_Helper_Form_Element_Text(
            'rssDesc', null, '或者，通过RSS订阅', _t('RSS订阅描述'),
            _t('RSS 订阅区域的提示文字')
        );
        $form->addInput($rssDesc);

    }

    /**
     * 个人配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 加载 Widget 文件，供主题调用
     */
    public static function loadWidget()
    {
        require_once __DIR__ . '/Widget/Subscribe.php';
    }

    /**
     * 文章发布时触发即时推送
     */
    public static function onPublish($contents, $widget)
    {
        Newsletter_Service::sendInstant($contents, $widget);
    }
}
