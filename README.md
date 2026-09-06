# Yikai CMS v1.19.8

[![CI](https://github.com/bluesailor/yikaicms/actions/workflows/ci.yml/badge.svg)](https://github.com/bluesailor/yikaicms/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg?logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-免费商用-green.svg)](./LICENSE)

**简体中文** · YikaiCMS - 轻量、安全、开箱即用的企业建站系统。PHP 8.0+（建议 8.2+）、MySQL/SQLite、Tailwind CSS v4、插件 Hooks、AI 内容助手，支持简体中文、English、日本語多语言网站建设。

**English** · YikaiCMS is a lightweight, secure, and ready-to-use CMS for business websites. Built with PHP 8.0+ (8.2+ recommended), MySQL/SQLite, Tailwind CSS v4, Plugin Hooks, and AI Content Assistant. Supports multilingual websites in Chinese, English, and Japanese.

**日本語** · YikaiCMS は、軽量・安全・すぐに使える企業向け CMS です。PHP 8.0+（8.2+ 推奨）、MySQL/SQLite、Tailwind CSS v4、プラグイン Hooks、AI コンテンツアシスタントを搭載し、中国語・英語・日本語の多言語サイト構築に対応しています。

官网：[https://www.yikaicms.com](https://www.yikaicms.com) · 演示：[https://demo.yikaicms.com](https://demo.yikaicms.com)

## 功能特性

### AI 内容助手
- **5 大 AI 供应商** — OpenAI、Claude (Anthropic)、DeepSeek、通义千问 (Qwen)、智谱AI (GLM)
- **一键生成** — 标题 + 摘要 + 标签 + URL别名 + 正文内容，一次生成
- **多种模式** — 生成文章、改写润色、续写扩展、SEO 优化、摘要生成
- **API Key 加密** — AES-128-CBC 加密存储，后台掩码显示
- **用量统计** — 调用日志、Token 统计、每日趋势

### 内容管理
- **栏目管理** — 无限层级栏目树，支持拖拽排序，8 种栏目类型（列表、单页、产品、案例、下载、招聘、相册、外链）
- **多级产品分类菜单 v1.7** — 产品分类支持任意层级嵌套，桌面端 hover 弹出 flyout 子菜单，三主题全适配
- **文章系统** — 多分类管理，置顶/推荐/热门标记，TinyMCE 富文本编辑
- **产品中心** — 多级分类，品牌管理，标签系统，图片组，规格参数，价格管理
- **案例展示** — 行业方案与成功案例
- **招聘管理** — 职位发布，薪资/学历/经验/工作性质筛选
- **下载中心** — 文件分类管理，本地上传与外链，下载计数
- **单页管理** — 企业简介、服务流程等静态页面，富文本与 Blox 可视化编辑；停用页收纳不占列表
- **发展历程时间线 v1.7** — 3 种布局可切换：竖向双边 / 横向 Swiper 滑块 / 紧凑列表，`[timeline]` 短码可在任意页面嵌入

### 页面构建器 v1.11
- **可视化区块编辑** — 区块(1-4列) → 列 → 元素三层结构，拖拽排序，17 种内置元素（标题/富文本/图片/按钮/图标/视频/CTA/卡片/提示条/引用/图标框/动态列表/轮播/导航…）
- **实时预览** — 编辑防抖刷新 iframe 预览，桌面/平板/手机三档视口
- **响应式三档** — 内边距/列间距/间距高度可按 桌面/平板/手机 分档设置，渲染 Tailwind 断点前缀
- **可复用块** — 区块存入块库，页面引用插入，改一处全站生效；也可副本插入独立编辑
- **预设库** — Hero/特性/CTA/团队/画廊/数据/评价 7 种区块预设 + 整页模板一键插入
- **动态元素** — 按栏目/自定义模型拉实时内容（view-time 渲染），插件可注册自定义元素

### 自定义内容模型 v1.11
- **模型即建即用** — 后台定义内容类型（团队/解决方案/FAQ…），自动获得增删改查、栏目绑定、前台列表/详情
- **字段复用** — 复用扩展字段体系定义模型字段，预置方案一键套用
- **标签打通** — `{yk:list type=模型key}` 标签与构建器动态列表直接消费模型内容

### 主题系统
- **3 套随包主题** — Default（标准）、Business（深色商务风）、Minimal（极简）
- **模板市场** — Aurora（渐变现代风）、Trade 等主题在后台「主题 → 模板市场」按需安装
- **文件覆盖机制** — layouts / blocks / partials 三层模板
- **主题规范** — theme.json Schema v1，安装时校验版本要求与必需模板；预览截图、后台一键切换

### 媒体管理
- **媒体库** — 图片与文件统一管理，自动缩略图
- **相册管理** — 多相册，图片拖拽排序
- **轮播图** — PC/移动端双图，定时展示，分组管理，短码嵌入

### 询盘与互动
- **询盘系统** — 产品详情页内联询盘表单，5 阶段状态管理
- **邮件通知** — 4 套邮件模板，变量替换引擎
- **表单系统** — 可视化表单设计器，`[form-slug]` 短码嵌入，AJAX 提交
- **会员系统** — 前台注册/登录，下载登录限制
- **友情链接** — Logo 展示，排序管理

### 首页定制
- 7 大可配置区块：轮播图、关于我们、数据统计、核心优势、栏目内容、客户评价、CTA
- 区块拖拽排序，独立开关，背景自定义
- 滚动入场动画（fade / stagger / 数字计数）

### 社交媒体
- **SNS 设置** — 后台可视化配置，支持微信、微博、X、Instagram、Facebook、YouTube 等 20+ 平台
- **页脚图标** — 自动显示彩色图标链接

### 系统管理
- **角色权限** — 超级管理员 / 编辑 / 运营，8 类权限细粒度控制
- **数据库管理** — 一键备份、按表导出、SQL导入、日志清理
- **升级检测** — 内置升级检测与一键执行
- **插件系统** — WordPress 风格钩子机制，热插拔
- **SEO 管理** — Sitemap / OG 标签 / 站长验证 / Canonical URL
- **安全设置** — 登录保护、IP 白名单、表单防刷
- **扩展字段** — 自定义内容/产品字段

### 插件生态

**预装插件**（随安装包附带）：

| 插件 | 说明 |
|------|------|
| 返回顶部 | 滚动后自动显示回到顶部按钮 |
| Cookie 同意横幅 | GDPR / PIPL 合规：三档授权、随时撤回、Google Consent Mode v2 |

**插件市场**（后台「插件管理 → 插件市场」浏览/搜索/一键安装，SHA256 + RSA 签名校验）：网站公告、后台菜单排序、数据库搜索替换、网站统计接入等，持续上架中。

---

## 环境要求

| 项目 | 要求 |
|------|------|
| PHP | >= 8.0（建议 8.2+） |
| 数据库 | MySQL 5.7+ / MySQL 8.0+（utf8mb4）或 SQLite 3 |
| Web 服务器 | Apache（启用 `mod_rewrite`）或 Nginx |
| PHP 扩展（必需） | pdo、json、mbstring、fileinfo、dom |
| PHP 扩展（推荐） | curl、openssl、gd、zip、simplexml（在线升级、缩略图、授权校验与 XLSX 导入需要） |

> 以上要求由 `includes/RuntimeRequirements.php` 统一定义，安装器、站点健康检查与兼容层都从那里读取；
> 改要求请只改那一处。`simplexml` 核心不依赖，仅 product-import 插件读 XLSX 时用到。

---

## 安装

### 1. 下载部署

```bash
# 从 GitHub 下载
git clone https://github.com/bluesailor/yikaicms.git

# 或下载 Release ZIP 上传至服务器
```

确保以下目录可写：`/config/`、`/uploads/`、`/storage/`

### 2. 运行安装向导

浏览器访问 `http://你的域名/install/`，按向导完成安装。

支持 MySQL 和 SQLite 两种数据库。

### 3. 配置伪静态

**「首页正常，点栏目就 404」几乎都是这一步没做。**

YikaiCMS 内置 PHP 路由分发器（`includes/Dispatcher.php`），只要把「不存在的文件」
统一交给 `index.php`，剩下的路由由 PHP 完成——等价于 WordPress 那条通用规则。
所以多数主机不需要逐条 rewrite，配一条 catch-all 即可。

#### Apache

`.htaccess` 已内置，确保启用 `mod_rewrite` 且 `AllowOverride All`。

#### 宝塔面板（Nginx）

站点 → **设置** → **伪静态** → 下拉选择 **wordpress** → 保存。就这一步，无需重启。

选 wordpress 预设即可，是因为它就是上面说的那条 catch-all：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

#### 阿里云 / 万网 云虚拟主机

主机控制面板 → **高级环境设置** → **NGINX 设置**，把默认的
`location / {}` 与 `location ~ /\.ht {deny all;}` 两段**整体替换**为下面内容，
保存即生效（无需重启）：

```nginx
# 1) 拦截敏感目录与文件
location ~ ^/(config|storage|vendor|includes|install/sql|bin|migrations|recipes)/ {
    deny all;
}
location ~ /\.(git|env|htaccess|htpasswd) {
    deny all;
}
location ~ ^/(composer\.(json|lock)|package(-lock)?\.json)$ {
    deny all;
}

# 2) 主规则：真实文件直出，其余交给 index.php
location / {
    if (!-e $request_filename) {
        rewrite ^ /index.php last;
    }
}
```

> 该面板只允许 `location` / `allow` / `deny` / `try_files` / `rewrite` / `return` / `if` / `set`，
> 且后七者必须写在 `location` 内——上面的写法已经遵守这些限制。
> 同样内容也放在源码的 `deploy/aliyun-nginx-minimal.txt`。

#### 自建 Nginx

完整示例见源码 `deploy/nginx-server.conf`，改完 `nginx -s reload`。

#### 配好了仍然 404？

按顺序排查：栏目是否已启用 → 别名（slug）是否与其它栏目/单页重名 →
栏目类型是否为「外链」（这类只跳转、本身没有页面）→
是否开了「静态生成」（开启后页面由已生成的静态文件提供，新栏目需重新生成一次）。

### 4. 安装后

- 删除或重命名 `/install/` 目录
- 确认 `DEBUG` 为 `false`

---

## 目录结构

```
├── admin/          # 后台管理
├── assets/         # 静态资源（CSS、JS、字体）
├── config/         # 配置文件
├── includes/       # 核心代码（模型、函数、钩子、AI、构建器引擎）
├── install/        # 安装向导 + SQL 脚本（MySQL / SQLite）
├── lang/           # 语言包
├── plugins/        # 插件目录
├── themes/         # 运行时主题目录（核心包仅内置 default）
├── marketplace/    # 可选主题市场源码（不进入 CMS 发布包）
├── uploads/        # 用户上传文件
└── storage/        # 缓存与日志
```

---

## 技术栈

| 层面 | 技术 |
|------|------|
| 后端 | PHP 8.0+，纯原生，无框架 |
| 数据库 | MySQL 5.7+ / 8.0+ / SQLite 3，PDO |
| 前端样式 | Tailwind CSS v4 |
| 前端交互 | Alpine.js v3 |
| 富文本 | TinyMCE |
| 轮播图 | Swiper |
| 拖拽排序 | SortableJS |
| AI 接口 | OpenAI / Anthropic / DeepSeek / Qwen / Zhipu |

---

## 许可证

本项目采用 [《YikaiCMS 软件许可协议》](LICENSE)，Copyright (c) 2026 Yikai：

- **免费商用**——个人与企业建站、为客户提供建站服务、修改源码、开发并出售自己的主题与插件，均无需付费；
- **前台无署名要求**——是否显示版权信息由你决定；
- 免费使用时，**后台**的 Powered by YikaiCMS 标识、官网链接与版本号需保留（取得商业授权可隐藏）；
- **软件本体的再分发、改名贴牌与上架软件市场需另行书面授权**——为单一客户交付建站成果不属于再分发。

随包第三方组件按其各自协议授权，清单见 [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md)。

## 相关链接

- 官网：[https://www.yikaicms.com](https://www.yikaicms.com)
- 演示：[https://demo.yikaicms.com](https://demo.yikaicms.com)
