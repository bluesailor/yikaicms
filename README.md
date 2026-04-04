# Yikai CMS

一款基于 PHP 8.0+ 的轻量级企业内容管理系统，无框架依赖，开箱即用。

官网：[https://www.yikaicms.com](https://www.yikaicms.com) · 演示：[https://demo.yikaicms.com](https://demo.yikaicms.com) · 文档：[https://www.yikaicms.com/docs.html](https://www.yikaicms.com/docs.html)

## 功能特性

### AI 内容助手
- **5 大 AI 供应商** — OpenAI、Claude (Anthropic)、DeepSeek、通义千问 (Qwen)、智谱AI (GLM)
- **一键生成** — 标题 + 摘要 + 标签 + URL别名 + 正文内容，一次生成
- **多种模式** — 生成文章、改写润色、续写扩展、SEO 优化、摘要生成
- **提示词面板** — 行业、受众、关键词、写作风格、字数、预设模板
- **API Key 加密** — AES-128-CBC 加密存储，后台掩码显示
- **用量统计** — 调用日志、Token 统计、每日趋势、供应商分布

### 内容管理
- **栏目管理** — 无限层级栏目树，支持拖拽排序，8 种栏目类型（列表、单页、产品、案例、下载、招聘、相册、外链）
- **文章系统** — 多分类管理，支持置顶、推荐、热门标记，SEO 字段，TinyMCE 富文本编辑
- **产品中心** — 多级分类，品牌管理，标签系统，图片组展示，规格参数，价格管理
- **案例展示** — 行业方案与成功案例分栏展示
- **招聘管理** — 职位发布，支持薪资、学历、经验、工作性质等字段筛选
- **下载中心** — 文件分类管理，支持本地上传与外链，下载计数
- **单页管理** — 企业简介、服务流程等静态页面

### 主题系统
- **多主题切换** — 后台一键切换，含 Default 和 Minimal 两套内置主题
- **文件覆盖机制** — layouts / blocks / partials 三层模板，主题目录优先，回退到默认
- **主题配置** — theme.json 声明元信息、支持的区块、预览截图

### 媒体管理
- **媒体库** — 统一管理图片与文件，上传自动生成缩略图
- **相册管理** — 多相册支持，图片拖拽排序
- **轮播图** — 支持 PC/移动端双图，定时展示，双按钮配置

### 询盘与互动
- **询盘系统** — 产品详情页内联询盘表单，5 阶段状态管理（新询盘→已联系→跟进中→成交→失败）
- **邮件通知** — 4 套邮件模板（注册、找回密码、重置密码、询盘通知），变量替换引擎
- **表单系统** — 可视化表单设计器，`[form-slug]` 短码嵌入页面，AJAX 提交
- **会员系统** — 前台注册/登录，可配置下载登录限制
- **友情链接** — Logo 展示，排序管理

### 首页定制
- 7 大可配置区块：轮播图、关于我们、数据统计、核心优势、栏目内容、客户评价、CTA
- 区块顺序拖拽调整，每个区块独立开关
- 滚动入场动画（fade / stagger / 数字计数）
- 主题色、导航布局、顶部通栏等全站样式设置

### 多语言
- **翻译管理** — 后台在线翻译语言包，支持 DeepL / Google Translate API
- **语言包** — 内置中文（zh-CN）和日语（ja）

### 系统管理
- **角色权限** — 超级管理员 / 编辑 / 运营等角色，8 类权限细粒度控制
- **操作日志** — 后台操作全程记录，可追溯
- **数据库升级** — 内置升级检测与一键执行，SQLite DDL 自动转换
- **插件系统** — WordPress 风格钩子机制，热插拔
- **SEO 管理** — Sitemap / Robots.txt / OG 标签 / 站长验证 / Canonical URL
- **安全设置** — 登录保护、登录记录、上传限制

### 内置插件
| 插件 | 说明 |
|------|------|
| 返回顶部 | 滚动后自动显示回到顶部按钮 |
| 数据库备份 | 导出 SQL 文件，支持 MySQL 5.7/8.0 格式选择 |
| 搜索替换 | 数据库全局搜索替换，支持预览 |
| 菜单排序 | 后台侧栏菜单拖拽排序 |

---

## 环境要求

| 项目 | 要求 |
|------|------|
| PHP | >= 8.0 |
| 数据库 | MySQL 5.7+（utf8mb4）或 SQLite 3 |
| Web 服务器 | Apache（需启用 `mod_rewrite`）或 Nginx |
| PHP 扩展（必需） | pdo、json、mbstring |
| PHP 扩展（推荐） | gd、openssl、curl、fileinfo |

---

## 安装步骤

### 1. 下载部署

从 [GitHub Releases](https://github.com/bluesailor/yikaicms/releases) 下载最新版 ZIP，上传至 Web 服务器根目录，确保以下目录可写：

```
/config/
/uploads/
/storage/
```

### 2. 安装 Composer 依赖

```bash
composer install --no-dev
```

### 3. 运行安装向导

浏览器访问 `http://你的域名/install/`，按向导完成 4 步安装。

### 4. 安装后安全处理

- 删除或重命名 `/install/` 目录
- 确认 `config/config.php` 中 `DEBUG` 为 `false`

---

## 目录结构

```
├── admin/              # 后台管理
│   └── includes/       # 后台公共文件（认证、AI面板、头尾模板）
├── assets/             # 静态资源
│   ├── css/            # Tailwind CSS v4（本地编译）
│   ├── tinymce/        # TinyMCE 富文本编辑器
│   ├── alpinejs/       # Alpine.js
│   ├── swiper/         # Swiper 轮播
│   └── sortable/       # SortableJS 拖拽
├── config/             # 配置文件
├── includes/           # 核心代码
│   ├── AiService.php   # AI 调用类（5 供应商）
│   ├── functions.php   # 工具函数
│   ├── hooks.php       # 钩子系统
│   ├── plugin.php      # 插件加载器
│   ├── models/         # 数据模型
│   ├── blocks/         # 首页区块模板
│   └── partials/       # 公共组件
├── install/            # 安装向导 + SQL 脚本
├── lang/               # 语言包（zh-CN、ja）
├── plugins/            # 插件目录
├── themes/             # 主题目录（default、minimal）
├── uploads/            # 用户上传
└── vendor/             # Composer 依赖
```

---

## 技术栈

| 层面 | 技术 |
|------|------|
| 后端 | PHP 8.0+，纯原生，无框架 |
| 数据库 | MySQL 5.7+ / SQLite 3，PDO |
| 前端样式 | Tailwind CSS v4 |
| 前端交互 | Alpine.js v3 |
| 富文本 | TinyMCE（支持字号选择、图片上传） |
| 轮播图 | Swiper |
| 拖拽排序 | SortableJS |
| 滚动动画 | IntersectionObserver（原生） |
| AI 接口 | OpenAI / Anthropic / DeepSeek / Qwen / Zhipu |
| 拼音转换 | overtrue/pinyin |

---

## 相关链接

- 官网：[https://www.yikaicms.com](https://www.yikaicms.com)
- 演示：[https://demo.yikaicms.com](https://demo.yikaicms.com)
- 文档：[https://www.yikaicms.com/docs.html](https://www.yikaicms.com/docs.html)
- 更新日志：[https://www.yikaicms.com/changelog.html](https://www.yikaicms.com/changelog.html)

## 许可证

Yikai CMS - 企业内容管理系统
