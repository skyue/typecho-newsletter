<?php

namespace Widget\Newsletter;

use Typecho\Common;
use Typecho\Widget;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Subscribe extends Widget
{
    public function execute()
    {
        // Widget output via render()
    }

    /**
     * 返回菜单链接 URL，用户在后台菜单中使用此值即可触发弹窗
     */
    public static function getMenuUrl(): string
    {
        return '#newsletter-subscribe';
    }

    /**
     * 渲染弹窗订阅表单 — 放在 footer.php 中 </body> 之前调用
     * 页面中任意 <a href="#newsletter-subscribe"> 点击即弹出
     */
    public function renderPopup()
    {
        $options = \Widget\Options::alloc();
        $actionUrl = Common::url('/action/newsletter-subscribe', $options->siteUrl);

        try {
            $config = $options->plugin('Newsletter');
        } catch (\Exception $e) {
            $config = null;
        }

        $title       = $config->formTitle ?? '订阅更新';
        $desc        = $config->formDesc ?? '不想错过新文章？输入邮箱即可在发布时收到邮件通知。';
        $placeholder = $config->formPlaceholder ?? 'your@email.com';
        $button      = $config->formButton ?? '订阅';
        $showRss       = !empty($config->showRss);
        $rssUrl        = $config->rssUrl ?? $options->feedUrl;
        $rssDesc       = $config->rssDesc ?? '或者，通过RSS订阅';
        $encodedRssUrl = urlencode($rssUrl);
        ?>
        <style>
        .nl-popup-overlay {
            position: fixed; inset: 0; z-index: 9998;
            background: rgba(0,0,0,0.5); display: flex;
            align-items: center; justify-content: center;
            opacity: 0; visibility: hidden;
            transition: opacity 0.25s, visibility 0.25s;
        }
        .nl-popup-overlay.active { opacity: 1; visibility: visible; }
        .nl-popup {
            background: #fff; border-radius: 12px;
            padding: 2rem 1.75rem 1.75rem;
            max-width: 420px; width: 90vw;
            position: relative; box-shadow: 0 12px 40px rgba(0,0,0,0.15);
            transform: translateY(8px);
            transition: transform 0.25s;
        }
        .nl-popup-overlay.active .nl-popup { transform: translateY(0); }
        .nl-popup-close {
            position: absolute; top: 12px; right: 14px;
            background: none; border: none; font-size: 22px;
            color: #999; cursor: pointer; line-height: 1;
            padding: 4px; transition: color 0.2s;
        }
        .nl-popup-close:hover { color: #333; }
        .nl-popup-title {
            font-size: 1.15rem; font-weight: 700; color: #333;
            margin: 0 0 0.25rem; text-align: center;
        }
        .nl-popup-desc {
            font-size: 0.9rem; color: #888; margin: 0 0 1.25rem;
            text-align: center; line-height: 1.5;
        }
        .nl-popup-form { display: flex; gap: 0.5rem; }
        .nl-popup-input {
            flex: 1; padding: 0.6rem 0.75rem;
            font-size: 1rem; border: 1px solid #ddd; border-radius: 6px;
            outline: none; transition: border-color 0.2s;
        }
        .nl-popup-input:focus { border-color: #467b96; }
        .nl-popup-btn {
            padding: 0.6rem 1.25rem; font-size: 0.95rem;
            background: #467b96; color: #fff; border: none;
            border-radius: 6px; cursor: pointer; white-space: nowrap;
            transition: background 0.2s;
        }
        .nl-popup-btn:hover { background: #3a6b82; }
        .nl-popup-divider {
            border: none; border-top: 1px solid #eee;
            margin: 1.25rem 0 1rem;
        }
        .nl-popup-rss {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.9rem; color: #888;
        }
        .nl-popup-rss-icon {
            flex-shrink: 0; width: 16px; height: 16px;
            background: #f26522; border-radius: 3px;
            display: flex; align-items: center; justify-content: center;
        }
        .nl-popup-rss-icon::after {
            content: ''; display: block; width: 7px; height: 7px;
            background: #fff; border-radius: 50%;
            box-shadow: 3px -3px 0 #fff, -3px -3px 0 #fff, 0 0 0 1.5px #f26522;
        }
        .nl-popup-rss-link {
            flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
            color: #888; text-decoration: none;
        }
        .nl-popup-rss-link:hover { color: #555; }
        .nl-popup-copy {
            flex-shrink: 0; padding: 0.25rem 0.7rem;
            font-size: 0.8rem; color: #888;
            background: #f5f5f5; border: 1px solid #ddd;
            border-radius: 4px; cursor: pointer;
            transition: color 0.2s, border-color 0.2s;
        }
        .nl-popup-copy:hover { color: #467b96; border-color: #467b96; }
        .nl-popup-copy.copied { color: #2a7d2a; border-color: #2a7d2a; }
        .nl-popup-readers {
            display: flex; gap: 0.5rem; margin-top: 0.75rem;
            justify-content: center; flex-wrap: wrap;
        }
        .nl-popup-readers a {
            display: inline-flex; align-items: center; gap: 0.3rem;
            padding: 0.35rem 0.75rem; font-size: 0.8rem;
            color: #888; text-decoration: none;
            background: #f5f5f5; border: 1px solid #ddd;
            border-radius: 4px; transition: color 0.2s, border-color 0.2s;
        }
        .nl-popup-readers a:hover { color: #467b96; border-color: #467b96; }
        @media (prefers-color-scheme: dark) {
            .nl-popup { background: #2b2520; box-shadow: 0 12px 40px rgba(0,0,0,0.4); }
            .nl-popup-title { color: #ede4d9; }
            .nl-popup-desc { color: #a8a099; }
            .nl-popup-close { color: #756e66; }
            .nl-popup-close:hover { color: #ede4d9; }
            .nl-popup-input {
                background: #1f1b17; color: #ede4d9;
                border-color: #3a332c;
            }
            .nl-popup-input:focus { border-color: #467b96; }
            .nl-popup-divider { border-color: #3a332c; }
            .nl-popup-rss { color: #a8a099; }
            .nl-popup-rss-link { color: #a8a099; }
            .nl-popup-rss-link:hover { color: #ede4d9; }
            .nl-popup-copy {
                color: #a8a099; background: #1f1b17;
                border-color: #3a332c;
            }
            .nl-popup-copy:hover { color: #467b96; border-color: #467b96; }
            .nl-popup-copy.copied { color: #8fcc85; border-color: #8fcc85; }
            .nl-popup-readers a {
                color: #a8a099; background: #1f1b17;
                border-color: #3a332c;
            }
            .nl-popup-readers a:hover { color: #467b96; border-color: #467b96; }
        }
        </style>
        <div class="nl-popup-overlay" id="nl-popup-overlay">
            <div class="nl-popup">
                <button class="nl-popup-close" id="nl-popup-close">&times;</button>
                <p class="nl-popup-title"><?php echo htmlspecialchars($title); ?></p>
                <p class="nl-popup-desc"><?php echo htmlspecialchars($desc); ?></p>
                <form class="nl-popup-form" method="post" action="<?php echo $actionUrl; ?>" novalidate>
                    <input type="email" name="email" class="nl-popup-input" placeholder="<?php echo htmlspecialchars($placeholder); ?>" required>
                    <button type="submit" class="nl-popup-btn"><?php echo htmlspecialchars($button); ?></button>
                </form>
                <?php if ($showRss): ?>
                <hr class="nl-popup-divider">
                <p class="nl-popup-desc"><?php echo htmlspecialchars($rssDesc); ?></p>
                <div class="nl-popup-rss">
                    <span class="nl-popup-rss-icon"></span>
                    <a class="nl-popup-rss-link" href="<?php echo htmlspecialchars($rssUrl); ?>" target="_blank"><?php echo htmlspecialchars($rssUrl); ?></a>
                    <button class="nl-popup-copy" id="nl-rss-copy"><?php _e('复制'); ?></button>
                </div>
                <div class="nl-popup-readers">
                    <a href="https://feedly.com/i/subscription/feed/<?php echo $encodedRssUrl; ?>" target="_blank" rel="noopener">Feedly</a>
                    <a href="https://www.inoreader.com/feed/<?php echo $encodedRssUrl; ?>" target="_blank" rel="noopener">Inoreader</a>
                    <a href="https://feedbin.com/?subscribe=<?php echo $encodedRssUrl; ?>" target="_blank" rel="noopener">Feedbin</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function(){
            var overlay = document.getElementById('nl-popup-overlay');
            var closer  = document.getElementById('nl-popup-close');
            function show(){ overlay.classList.add('active'); }
            function hide(){ overlay.classList.remove('active'); }
            closer.addEventListener('click', hide);
            overlay.addEventListener('click', function(e){
                if (e.target === overlay) hide();
            });
            document.addEventListener('click', function(e){
                var a = e.target.closest('a[href="#newsletter-subscribe"]');
                if (a) { e.preventDefault(); show(); }
            });
            window.addEventListener('hashchange', function(){
                if (location.hash === '#newsletter-subscribe') show();
            });
            if (location.hash === '#newsletter-subscribe') show();
            var copyBtn = document.getElementById('nl-rss-copy');
            if (copyBtn) {
                copyBtn.addEventListener('click', function(){
                    var url = <?php echo json_encode($rssUrl); ?>;
                    navigator.clipboard.writeText(url).then(function(){
                        copyBtn.textContent = '<?php _e('已复制'); ?>';
                        copyBtn.classList.add('copied');
                        setTimeout(function(){
                            copyBtn.textContent = '<?php _e('复制'); ?>';
                            copyBtn.classList.remove('copied');
                        }, 2000);
                    });
                });
            }
        })();
        </script>
        <?php
    }
}
