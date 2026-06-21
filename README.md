# Newsletter

Typecho 邮件订阅插件 — 文章发布时通过 AWS SES 即时推送给所有订阅者。

## 功能

- **弹窗订阅** — 在主题任意位置添加 `#newsletter-subscribe` 链接，点击弹出订阅窗口
- **双重确认** — 订阅后发送确认邮件，确认后才生效
- **即时推送** — 文章发布时自动向所有已确认订阅者发送邮件
- **全文/摘要** — 邮件内容可选全文或摘要模式
- **RSS 订阅** — 弹窗中可选展示 RSS 链接及常用阅读器快捷入口（Feedly、Inoreader、Feedbin）
- **邮件模板** — 可自定义确认邮件和推送邮件的 HTML 模板
- **后台管理** — 查看、添加、删除订阅者；发送测试邮件；查看发送历史
- **暗色模式** — 弹窗自动适配浅色/深色模式

## 环境要求

- Typecho 1.3.0+
- PHP 7.4+
- AWS SES（Simple Email Service）账号

## 安装

```bash
cd /path/to/typecho/usr/plugins
git clone https://github.com/skyue/Typecho-Newsletter.git Newsletter
```

然后在 Typecho 后台「控制台 → 插件」中启用 **Newsletter**。

## 配置

启用后进入「插件设置」：

1. **AWS 凭证** — 填写 Access Key ID、Secret Access Key、区域（如 `us-east-1`）
2. **发件邮箱** — 需在 AWS SES 中已验证的邮箱或域名
3. **邮件内容模式** — 选择全文或摘要 + 链接
4. **分类过滤**（可选）— 仅发送指定分类的文章
5. **弹窗文案** — 自定义标题、描述、按钮文字
6. **RSS 订阅**（可选）— 在弹窗中展示 RSS 链接

> 建议为 SES 使用最小权限 IAM 策略，仅授予 `ses:SendEmail` 权限。

## 在主题中添加订阅入口

**1. 添加链接**

在任意位置（导航菜单、侧边栏、页脚等）放置链接，URL 填写：

```
#newsletter-subscribe
```

例如在后台「外观 → 菜单」中添加菜单项，或在模板中手动写：

```html
<a href="#newsletter-subscribe">订阅</a>
```

**2. 在 footer.php 中插入弹窗**

在主题 `footer.php` 的 `</body>` 之前添加：

```php
<?php if (class_exists('Widget\Newsletter\Subscribe')) { \Widget\Newsletter\Subscribe::alloc()->renderPopup(); } ?>
```

完成。点击订阅链接即可弹出订阅窗口。

## 测试

配置完成后，在后台「Newsletter → 发送测试」中填写自己的邮箱地址，点击发送测试邮件，验证配置是否正确。

## 许可

GPL 2.0 — 详见 [LICENSE](LICENSE.md)
