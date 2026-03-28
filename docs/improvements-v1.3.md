# Yikai CMS v1.3 改进记录

日期：2026-03-26

本次改进聚焦于 SEO 管理、图片性能优化、安全设置、架构合并四个方向。

---

## 1. SEO 完整方案

### 1.1 Sitemap XML 自动生成（sitemap.php）
- 自动收录：首页、栏目页、文章、产品（各最多 5000 条）
- 包含 lastmod、changefreq、priority、image:image
- 可配置缓存时间，后台一键刷新
- .htaccess 配置 /sitemap.xml 路由

### 1.2 robots.txt
- 新增根目录 robots.txt，屏蔽敏感路径
- 后台可在线编辑，即时生效

### 1.3 OpenGraph + Twitter Card
- header.php 自动输出 og:title/description/image/type/url
- 各页面传入对应数据（文章=Article、产品=Product、单页=WebPage）
- 支持后台配置默认分享图片

### 1.4 JSON-LD 结构化数据
- 全站输出 Organization schema
- 文章页：Article schema（含发布/修改时间）
- 产品页：Product schema（含价格/库存）
- 单页：WebPage schema

### 1.5 Canonical URL
- 所有页面自动生成 `<link rel="canonical">`

### 1.6 搜索引擎验证
- 支持百度/Google/Bing 站长验证码配置
- 自动输出对应 meta 标签

### 1.7 首页独立 SEO 标题
- 后台可设置独立于站点名称的首页 title

### 1.8 后台 SEO 设置页面（admin/setting_seo.php）
- 5 个 Tab：基础设置、社交分享、站长验证、Sitemap、Robots.txt
- 侧边栏新增「SEO 设置」入口

---

## 2. 图片性能优化

### 2.1 WebP 自动转换
- 上传 JPG/PNG 时自动生成 WebP 副本（质量 80%）
- 新增 convertToWebp() 和 webpUrl() 函数
- uploadFile() 返回值新增 webp_url 字段

### 2.2 图片懒加载
- 所有前台页面 img 标签添加 loading="lazy"
- Banner 轮播图保持即时加载（首屏可见）
- 覆盖文件：list, news, article, product, page, detail, contact, history
- 首页区块：about, channel, testimonials

### 2.3 浏览器缓存增强（.htaccess）
- 图片/字体：1 年强缓存 + immutable
- CSS/JS：1 个月
- HTML/PHP：no-cache
- 新增 ETag、Cache-Control 头
- GZIP 压缩扩展到 SVG 和字体
- WebP MIME 类型声明

---

## 3. 安全设置后台（admin/setting_security.php）

### 3.1 登录安全 Tab
- 登录失败最大次数（可配置，默认 5）
- 锁定时长（可配置，默认 15 分钟）
- Session 超时时间（可配置，默认 30 分钟）
- 密码最小长度
- 后台 IP 白名单（支持 CIDR）

### 3.2 上传安全 Tab
- 最大文件大小（MB）
- 允许的图片类型
- 允许的文件类型
- 安全机制说明（展示已启用的保护措施）

### 3.3 日志管理 Tab
- 操作日志统计（总数、最早记录、限流文件数）
- 日志清理（可选 7/30/60/90 天前）
- 一键清除登录/表单限流记录（解锁被误锁的用户）
- 链接到详细日志查看页面

### 3.4 配置生效
- checkLoginThrottle() 改为读取 config('login_max_attempts') 和 config('login_lock_minutes')
- checkFormThrottle() 改为读取 config('form_max_submits') 和 config('form_throttle_minutes')

---

## 4. 架构合并：文章系统统一到内容表

### 问题
系统存在两套并行的内容存储：
- `yikai_articles` + `yikai_article_categories`（文章专用）
- `yikai_contents` + `yikai_channels`（通用内容）

两套表字段几乎完全相同，导致数据冗余和维护困难。

### 解决方案
将 articles 系统合并到 contents + channels 体系：

1. **数据迁移**（通过 admin/upgrade.php 升级检测执行）
   - `article_categories` → 创建为 `channels` 表中 news 栏目的子栏目
   - `articles` → 插入 `contents` 表，`category_id` 映射为 `channel_id`
   - 迁移后清空 articles 表数据（保留表结构以防回退）

2. **前台改造**
   - `news.php` — 改用 ContentModel + ChannelModel 查询
   - `article.php` — 改用 ContentModel 查询
   - `index.php` — 移除 news 栏目的特殊处理，统一走 ContentModel

3. **后台改造**
   - `admin/article.php` — 改用 ContentModel，栏目筛选改用 channels
   - `admin/article_edit.php` — 保存到 contents 表，分类选择改用 channels

4. **URL 兼容**
   - 所有 URL 保持不变：/news.html, /news/article/1.html 等
   - .htaccess 无需修改

5. **首页区块**
   - `includes/blocks/channel.php` — 移除 is_article 特殊判断

### 升级步骤
1. 访问 后台 → 升级管理
2. 点击「合并文章系统到统一内容表」执行
3. 系统自动完成分类迁移和数据迁移

---

## 改动文件清单

| 文件 | 类型 | 说明 |
|------|------|------|
| sitemap.php | 新增 | Sitemap XML 生成器 |
| robots.txt | 新增 | 搜索引擎爬虫规则 |
| admin/setting_seo.php | 新增 | SEO 设置页面（5 Tab） |
| admin/setting_security.php | 新增 | 安全设置页面（3 Tab） |
| .htaccess | 修改 | Sitemap 路由 + 缓存增强 |
| includes/header.php | 修改 | OG/Twitter/JSON-LD/canonical/验证码 meta |
| includes/functions.php | 修改 | WebP转换、webpUrl()、限流读取配置 |
| admin/includes/header.php | 修改 | 侧边栏新增 SEO 设置和安全设置 |
| admin/includes/auth.php | 修改 | 登录限流读取配置 |
| index.php | 修改 | 首页 canonical + SEO 标题 |
| article.php | 修改 | OG + JSON-LD + 懒加载 |
| product.php | 修改 | OG + JSON-LD + 懒加载 |
| page.php | 修改 | OG + canonical + 懒加载 |
| detail.php | 修改 | OG + JSON-LD + 懒加载 |
| news.php | 修改 | 懒加载 |
| list.php | 修改 | 懒加载 |
| contact.php | 修改 | 懒加载 |
| history.php | 修改 | 懒加载 |
| includes/blocks/about.php | 修改 | 懒加载 |
| includes/blocks/channel.php | 修改 | 懒加载 |
| includes/blocks/testimonials.php | 修改 | 懒加载 |

## 数据库变更

**无新表**。所有配置通过 settings 表的 key-value 存储，首次保存时自动创建：
- seo_title, site_keywords, site_description
- seo_og_image, seo_baidu_verify, seo_google_verify, seo_bing_verify
- seo_sitemap_enabled, seo_sitemap_ttl
- login_max_attempts, login_lock_minutes, session_timeout
- password_min_length, admin_ip_whitelist
- upload_max_size_mb, upload_image_types, upload_file_types
- form_max_submits, form_throttle_minutes

无需升级脚本，兼容现有数据库。
