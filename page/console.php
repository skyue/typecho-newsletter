<?php
include 'header.php';
include 'menu.php';

$console = Typecho_Widget::widget('Newsletter_Console');
$act = $request->get('act', 'subscribers');
$titles = [
    'subscribers' => _t('订阅者管理'),
    'log'         => _t('发送历史'),
    'test'        => _t('发送测试'),
    'templates'   => _t('邮件模板'),
    'help'        => _t('使用说明'),
];
$title = $titles[$act] ?? _t('订阅者管理');

// 处理操作
if ($request->isPost()) {
    if ($request->get('do') === 'delete_subscriber') {
        $console->deleteSubscriber($request->get('id'));
        $notice = _t('已删除');
    } elseif ($request->get('do') === 'add_subscriber') {
        $msg = $console->addSubscriber($request->get('add_email'));
        $notice = $msg ?: _t('已添加');
    } elseif ($request->get('do') === 'testMail') {
        $result = $console->sendTest($request->get('testTo'));
        $notice = $result['success']
            ? _t($result['message'] ?? '测试邮件发送成功')
            : _t('发送失败：') . ($result['message'] ?? '');
    } elseif ($request->get('do') === 'saveTemplate') {
        $console->saveTemplate($request->get('tpl'), $request->get('tplContent'));
        $notice = _t('模板已保存');
    }
}
?>

<main class="main">
<div class="body container">
  <div class="typecho-page-title">
    <h2><?php echo $title; ?></h2>
  </div>

  <div class="row typecho-page-main" role="main">
    <div class="col-mb-12">
      <ul class="typecho-option-tabs fix-tabs clearfix">
        <li<?php if ($act === 'subscribers'): ?> class="current"<?php endif; ?>>
          <a href="<?php $options->adminUrl('extending.php?panel=Newsletter%2Fpage%2Fconsole.php&act=subscribers'); ?>"><?php _e('订阅者管理'); ?></a>
        </li>
        <li<?php if ($act === 'log'): ?> class="current"<?php endif; ?>>
          <a href="<?php $options->adminUrl('extending.php?panel=Newsletter%2Fpage%2Fconsole.php&act=log'); ?>"><?php _e('发送历史'); ?></a>
        </li>
        <li<?php if ($act === 'test'): ?> class="current"<?php endif; ?>>
          <a href="<?php $options->adminUrl('extending.php?panel=Newsletter%2Fpage%2Fconsole.php&act=test'); ?>"><?php _e('发送测试'); ?></a>
        </li>
        <li<?php if ($act === 'templates'): ?> class="current"<?php endif; ?>>
          <a href="<?php $options->adminUrl('extending.php?panel=Newsletter%2Fpage%2Fconsole.php&act=templates'); ?>"><?php _e('邮件模板'); ?></a>
        </li>
        <li<?php if ($act === 'help'): ?> class="current"<?php endif; ?>>
          <a href="<?php $options->adminUrl('extending.php?panel=Newsletter%2Fpage%2Fconsole.php&act=help'); ?>"><?php _e('使用说明'); ?></a>
        </li>
        <li>
          <a href="<?php $options->adminUrl('options-plugin.php?config=Newsletter'); ?>"><?php _e('插件设置'); ?></a>
        </li>
      </ul>
    </div>

    <?php if (isset($notice)): ?>
    <div class="col-mb-12">
      <div class="message notice"><?php echo $notice; ?></div>
    </div>
    <?php endif; ?>

    <div class="col-mb-12 col-tb-12 col-12">
    <?php if ($act === 'subscribers'): ?>
      <div class="typecho-list">
        <form method="post" style="margin-bottom:16px;display:flex;gap:8px">
          <input type="hidden" name="do" value="add_subscriber">
          <input type="hidden" name="act" value="subscribers">
          <input type="email" name="add_email" class="text" placeholder="<?php _e('输入邮箱手动添加'); ?>" required style="flex:1;max-width:300px">
          <button type="submit" class="btn btn-s primary"><?php _e('添加'); ?></button>
        </form>
        <?php $subs = $console->getSubscribers(); ?>
        <?php if (empty($subs)): ?>
          <p><?php _e('暂无订阅者'); ?></p>
        <?php else: ?>
          <table class="typecho-list-table">
            <thead>
              <tr>
                <th><?php _e('邮箱'); ?></th>
                <th><?php _e('状态'); ?></th>
                <th><?php _e('订阅时间'); ?></th>
                <th><?php _e('确认时间'); ?></th>
                <th><?php _e('操作'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($subs as $sub): ?>
              <tr>
                <td><?php echo htmlspecialchars($sub['email']); ?></td>
                <td>
                  <?php
                  $statusLabels = [
                      'pending'      => '<span style="color:#f0ad4e">' . _t('待确认') . '</span>',
                      'active'       => '<span style="color:#5cb85c">' . _t('已确认') . '</span>',
                      'unsubscribed' => '<span style="color:#999">' . _t('已退订') . '</span>',
                  ];
                  echo $statusLabels[$sub['status']] ?? $sub['status'];
                  ?>
                </td>
                <td><?php echo date('Y-m-d H:i', $sub['created_at']); ?></td>
                <td><?php echo $sub['confirmed_at'] ? date('Y-m-d H:i', $sub['confirmed_at']) : '-'; ?></td>
                <td>
                  <form method="post" onsubmit="return confirm('<?php _e('确认删除？'); ?>')" style="display:inline">
                    <input type="hidden" name="do" value="delete_subscriber">
                    <input type="hidden" name="id" value="<?php echo $sub['id']; ?>">
                    <input type="hidden" name="act" value="subscribers">
                    <button type="submit" class="btn btn-s btn-warn"><?php _e('删除'); ?></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <?php elseif ($act === 'log'): ?>
      <div class="typecho-list">
        <?php $logs = $console->getDigestLogs(); ?>
        <?php if (empty($logs)): ?>
          <p><?php _e('暂无发送记录'); ?></p>
        <?php else: ?>
          <table class="typecho-list-table">
            <thead>
              <tr>
                <th><?php _e('发送时间'); ?></th>
                <th><?php _e('文章数'); ?></th>
                <th><?php _e('发送人数'); ?></th>
                <th><?php _e('文章 ID'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $log): ?>
              <tr>
                <td><?php echo date('Y-m-d H:i', $log['sent_at']); ?></td>
                <td><?php echo substr_count($log['post_ids'], ',') + 1; ?></td>
                <td><?php echo $log['subscriber_count']; ?></td>
                <td><?php echo htmlspecialchars($log['post_ids']); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <?php elseif ($act === 'test'): ?>
      <div class="col-mb-12 col-tb-8 col-9 content">
        <form method="post">
          <ul class="typecho-option" id="typecho-option-item-testTo-0">
            <li>
              <label class="typecho-label" for="testTo-0"><?php _e('收件人邮箱 *'); ?></label>
              <input type="email" name="testTo" id="testTo-0" class="text w-100" placeholder="<?php _e('输入测试收件邮箱'); ?>" required>
            </li>
          </ul>
          <ul class="typecho-option typecho-option-submit">
            <li>
              <input type="hidden" name="do" value="testMail">
              <input type="hidden" name="act" value="test">
              <button type="submit" class="btn primary"><?php _e('发送测试邮件'); ?></button>
              <p class="description" style="margin-top:8px;color:#999;font-size:13px"><?php _e('点击按钮后，将使用插件当前配置的 AWS SES 凭证、邮件模板和内容模式，向上述邮箱发送最新一篇文章的邮件。'); ?></p>
            </li>
          </ul>
        </form>
      </div>

    <?php elseif ($act === 'templates'): ?>
      <?php $tpl = $request->get('tpl', 'confirm'); ?>
      <div class="col-mb-12">
        <ul class="typecho-option-tabs fix-tabs clearfix" style="margin-bottom:1em">
          <li<?php if ($tpl === 'confirm'): ?> class="current"<?php endif; ?>>
            <a href="<?php $options->adminUrl('extending.php?panel=Newsletter%2Fpage%2Fconsole.php&act=templates&tpl=confirm'); ?>"><?php _e('确认邮件'); ?></a>
          </li>
          <li<?php if ($tpl === 'instant'): ?> class="current"<?php endif; ?>>
            <a href="<?php $options->adminUrl('extending.php?panel=Newsletter%2Fpage%2Fconsole.php&act=templates&tpl=instant'); ?>"><?php _e('即时推送'); ?></a>
          </li>
        </ul>
      </div>
      <div class="col-mb-12 col-tb-8 col-9 content">
        <form method="post">
          <label class="sr-only" for="tplContent"><?php _e('模板内容'); ?></label>
          <textarea name="tplContent" id="tplContent" class="w-100 mono" style="height:500px"><?php
              echo htmlspecialchars($console->getTemplate($tpl));
          ?></textarea>
          <p class="submit" style="margin-top:12px">
            <input type="hidden" name="do" value="saveTemplate">
            <input type="hidden" name="act" value="templates">
            <input type="hidden" name="tpl" value="<?php echo htmlspecialchars($tpl); ?>">
            <button type="submit" class="btn primary"><?php _e('保存模板'); ?></button>
          </p>
        </form>
      </div>

    <?php elseif ($act === 'help'): ?>
      <div class="col-mb-12 col-tb-8 col-9 content">
        <h3><?php _e('在主题中添加订阅入口'); ?></h3>
        <ol style="margin-left:1.5em;color:#666;line-height:2">
          <li><?php _e('在你希望的位置放置订阅入口（如导航菜单、侧边栏、页脚等），链接 URL 填写：'); ?>
            <pre style="background:#f5f5f5;padding:8px 12px;border-radius:4px;margin:4px 0"><code>#newsletter-subscribe</code></pre>
            <p style="margin-top:4px;font-size:13px"><?php _e('例如在「控制台 → 外观 → 菜单」中添加菜单项，或在主题模板中手动写 <code>&lt;a href="#newsletter-subscribe"&gt;订阅&lt;/a&gt;</code>。'); ?></p>
          </li>
          <li><?php _e('在主题 <code>footer.php</code> 的 <code>&lt;/body&gt;</code> 之前插入：'); ?>
            <pre style="background:#f5f5f5;padding:8px 12px;border-radius:4px;margin:4px 0"><code>&lt;?php if (class_exists('Widget\Newsletter\Subscribe')) { \Widget\Newsletter\Subscribe::alloc()->renderPopup(); } ?&gt;</code></pre>
          </li>
        </ol>
        <p><?php _e('完成。点击任意 <code>#newsletter-subscribe</code> 链接即弹出订阅窗口，自动适配浅色/深色模式，RSS 订阅开启后也会一并展示。'); ?></p>

      </div>

    <?php endif; ?>
    </div>
  </div>
</div>
</main>

<?php
include 'copyright.php';
include 'common-js.php';
include 'footer.php';
?>
