# Yikai CMS v1.8.1

[![CI](https://github.com/bluesailor/yikaicms/actions/workflows/ci.yml/badge.svg)](https://github.com/bluesailor/yikaicms/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.0%20%7C%208.1%20%7C%208.2%20%7C%208.3%20%7C%208.4-777BB4.svg?logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![Tests](https://img.shields.io/badge/tests-157%20passing-brightgreen.svg)](./tests)

**简体中文** · YikaiCMS - 轻量、安全、开箱即用的企业建站系统。PHP 8.0+、MySQL/SQLite、Tailwind CSS v4、插件 Hooks、AI 内容助手，支持中文（简体/繁体）、English、日本語多语言网站建设。

**English** · YikaiCMS is a lightweight, secure, and ready-to-use CMS for business websites. Built with PHP 8.0+, MySQL/SQLite, Tailwind CSS v4, Plugin Hooks, and AI Content Assistant. Supports multilingual websites in Chinese, English, and Japanese.

**日本語** · YikaiCMS は、軽量・安全・すぐに使える企業向け CMS です。PHP 8.0+、MySQL/SQLite、Tailwind CSS v4、プラグイン Hooks、AI コンテンツアシスタントを搭載し、中国語・英語・日本語の多言語サイト構築に対応しています。

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
- **单页管理** — 企业简介、服务流程等静态页面，支持排版编辑器
- **发展历程时间线 v1.7** — 3 种布局可切换：竖向双边 / 横向 Swiper 滑块 / 紧凑列表，`[timeline]` 短码可在任意页面嵌入

### 主题系统
- **3 套内置主题** — Default（标准）、Minimal（极简）、Business（深色商务风）
- **文件覆盖机制** — layouts / blocks / partials 三层模板
- **主题配置** — theme.json 元信息、预览截图、后台一键切换

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

### 内置插件

| 插件 | 说明 |
|------|------|
| 返回顶部 | 滚动后自动显示回到顶部按钮 |
| 菜单排序 | 后台侧栏菜单拖拽排序 |
| 搜索替换 | 数据库全局搜索替换，支持预览 |

---

## 环境要求

| 项目 | 要求 |
|------|------|
| PHP | >= 8.0 |
| 数据库 | MySQL 5.7+ / MySQL 8.0+（utf8mb4）或 SQLite 3 |
| Web 服务器 | Apache（启用 `mod_rewrite`）或 Nginx |
| PHP 扩展（必需） | pdo、json、mbstring |
| PHP 扩展（推荐） | gd、openssl、curl、fileinfo |

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

- **Apache** — `.htaccess` 已内置，确保启用 `mod_rewrite` + `AllowOverride All`
- **Nginx** — 安装完成页提供 rewrite 规则

### 4. 安装后

- 删除或重命名 `/install/` 目录
- 确认 `DEBUG` 为 `false`

---

## 目录结构

```
├── admin/          # 后台管理
├── assets/         # 静态资源（CSS、JS、字体）
├── config/         # 配置文件
├── includes/       # 核心代码（模型、函数、钩子、AI）
├── install/        # 安装向导 + SQL 脚本（MySQL / SQLite）
├── lang/           # 语言包
├── plugins/        # 插件目录
├── themes/         # 主题目录（default / minimal / business）
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

MIT License

## 相关链接

- 官网：[https://www.yikaicms.com](https://www.yikaicms.com)
- 演示：[https://demo.yikaicms.com](https://demo.yikaicms.com)
