# Yikai CMS

一款基于 PHP 8.0+ 的轻量级企业内容管理系统，无框架依赖，开箱即用。

官网：[https://www.yikaicms.com](https://www.yikaicms.com)

## 功能特性

### 内容管理
- **栏目管理** — 无限层级栏目树，支持拖拽排序，8 种栏目类型（列表、单页、产品、案例、下载、招聘、相册、外链）
- **文章系统** — 多分类管理，支持置顶、推荐、热门标记，SEO 字段，富文本编辑
- **产品中心** — 多级分类，图片组展示，规格参数（JSON），价格管理
- **案例展示** — 行业方案与成功案例分栏展示
- **招聘管理** — 职位发布，支持薪资、学历、经验、工作性质等字段筛选
- **下载中心** — 文件分类管理，支持本地上传与外链，下载计数
- **单页管理** — 企业简介、服务流程等静态页面

### 媒体管理
- **媒体库** — 统一管理图片与文件，上传自动生成缩略图（300x300 裁剪 + 800x600 等比缩放）
- **相册管理** — 多相册支持，图片拖拽排序
- **轮播图** — 支持 PC/移动端双图，定时展示，双按钮配置

### 互动功能
- **表单系统** — 可视化表单设计器，`[form-slug]` 短码嵌入页面，AJAX 提交，跟进管理
- **会员系统** — 前台注册/登录，可配置下载登录限制
- **友情链接** — Logo 展示，排序管理

### 首页定制
- 7 大可配置区块：轮播图、关于我们、数据统计、核心优势、栏目内容、客户评价、CTA
- 区块顺序拖拽调整，每个区块独立开关
- 主题色、导航布局、顶部通栏等全站样式设置

### 系统管理
- **角色权限** — 超级管理员 / 编辑 / 运营等角色，8 类权限细粒度控制
- **操作日志** — 后台操作全程记录，可追溯
- **数据库升级** — 内置升级检测与一键执行
- **插件系统** — WordPress 风格钩子机制，热插拔

### 内置插件
| 插件 | 说明 |
|------|------|
| 返回顶部 | 滚动后自动显示回到顶部按钮 |
| 数据库备份 | 一键导出数据库为 SQL 文件 |
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
| PHP 扩展（推荐） | gd、openssl、fileinfo |

---

## 安装步骤

### 1. 下载部署

将项目文件上传至 Web 服务器根目录（或子目录），确保以下目录可写：

```
/config/
/uploads/
/storage/
```

Linux/Mac 设置权限：

```bash
chmod -R 755 config/ uploads/ storage/
```

### 2. 安装 Composer 依赖

```bash
composer install --no-dev
```

### 3. 配置 Web 服务器

**Apache** — 项目已包含 `.htaccess`，确保启用 `mod_rewrite` 即可。

**Nginx** — 参考项目根目录的 `nginx.conf`，核心配置：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass 127.0.0.1:9000;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}

# 禁止访问敏感目录
location ~ ^/(config|storage|vendor|includes|install/sql)/ {
    deny all;
}
```

### 4. 运行安装向导

浏览器访问：

```
http://你的域名/install/
```

安装向导共 4 步：

1. **环境检测** — 自动检查 PHP 版本、扩展、目录权限，全部通过方可继续
2. **数据库配置** — 选择 MySQL 或 SQLite，填写连接信息，可点击"测试连接"验证
3. **管理员设置** — 设置站点名称、管理员账号密码，点击"安装"自动建表并写入配置
4. **安装完成** — 显示成功页面，可直接进入后台或前台

### 5. 安装后安全处理

- 删除或重命名 `/install/` 目录
- 确认 `config/config.php` 中 `DEBUG` 为 `false`
- 修改默认管理员密码

---

## 使用指南

### 后台登录

```
http://你的域名/admin/
```

使用安装时设置的管理员账号登录。

### 日常操作流程

1. **配置站点** — 进入"系统设置"，完善站点名称、Logo、联系方式、SEO 信息
2. **管理栏目** — 在"栏目管理"中调整导航结构，设置栏目类型和排序
3. **发布内容** — 根据栏目类型发布文章、产品、案例、招聘等内容
4. **上传媒体** — 通过媒体库或编辑器内直接上传图片和文件
5. **定制首页** — 在"首页设置"中配置区块内容、顺序和开关
6. **处理表单** — 在"数据管理 > 表单数据"中查看和跟进用户提交

### 权限说明

| 角色 | 权限范围 |
|------|----------|
| 超级管理员 | 全部功能，含用户管理、角色管理、系统设置 |
| 编辑 | 内容管理、媒体库 |
| 运营 | 内容、媒体、表单、轮播图、友链 |

可在"角色管理"中自定义角色权限。

---

## URL 路由

系统使用伪静态 `.html` 后缀，主要路由规则：

| URL | 说明 |
|-----|------|
| `/about.html` | 单页（按别名） |
| `/about/company.html` | 子页面 |
| `/list/1.html` | 栏目列表（按 ID） |
| `/list/1/page/2.html` | 栏目列表分页 |
| `/detail/1.html` | 内容详情（按 ID） |
| `/news.html` | 新闻列表 |
| `/news/company-news.html` | 新闻分类 |
| `/news/article/slug.html` | 文章详情 |
| `/product/1.html` | 产品详情（按 ID） |
| `/product/cat-slug.html` | 产品分类 |
| `/contact.html` | 联系我们 |

---

## 目录结构

```
├── admin/              # 后台管理（~45 个页面）
│   └── includes/       # 后台公共文件（认证、头尾模板）
├── api/                # API 接口
├── assets/             # 静态资源
│   ├── css/            # 样式文件（Tailwind CSS v4 本地编译）
│   ├── alpinejs/       # Alpine.js 框架
│   ├── wangeditor/     # wangEditor 富文本编辑器
│   ├── swiper/         # Swiper 轮播组件
│   ├── sortable/       # SortableJS 拖拽排序
│   └── aos/            # AOS 滚动动画
├── config/             # 配置文件
│   ├── config.php      # 主配置（安装时自动生成）
│   ├── database.php    # 数据库类
│   └── defaults.php    # 系统默认值
├── includes/           # 核心代码
│   ├── init.php        # 前台引导
│   ├── functions.php   # 200+ 工具函数
│   ├── hooks.php       # 钩子系统
│   ├── plugin.php      # 插件加载器
│   ├── models/         # 24 个数据模型
│   └── blocks/         # 首页区块模板
├── install/            # 安装向导
│   └── sql/            # 数据库脚本
├── lang/               # 语言包（zh-CN）
├── member/             # 前台会员页面
├── plugins/            # 插件目录
├── storage/            # 运行时存储（日志、缓存）
├── themes/             # 主题目录
├── uploads/            # 用户上传文件
└── vendor/             # Composer 依赖
```

---

## 技术栈

| 层面 | 技术 |
|------|------|
| 后端 | PHP 8.0+，纯原生，无框架 |
| 数据库 | MySQL 5.7+ / SQLite 3，PDO |
| 前端样式 | Tailwind CSS v4.1.18（本地编译） |
| 前端交互 | Alpine.js v3 |
| 富文本 | wangEditor v5.1.23 |
| 轮播图 | Swiper |
| 拖拽排序 | SortableJS v1.15.6 |
| 滚动动画 | AOS |
| 拼音转换 | overtrue/pinyin（自动生成 URL 别名） |

---

## 插件开发

在 `/plugins/` 下创建插件目录：

```
/plugins/my-plugin/
├── plugin.json
└── main.php
```

**plugin.json** 示例：

```json
{
    "name": "我的插件",
    "description": "插件功能描述",
    "version": "1.0.0",
    "author": "作者名",
    "requires_php": "8.0",
    "requires_cms": "1.0.0"
}
```

**main.php** 示例：

```php
<?php
// 前台 </body> 前注入内容
add_action('ik_footer_scripts', function() {
    echo '<script>console.log("Hello from my plugin!");</script>';
});

// 后台菜单注入
add_action('ik_admin_footer_scripts', function() {
    // 添加后台功能
});
```

可用钩子：

| 钩子 | 位置 |
|------|------|
| `ik_head` | 前台 `</head>` 前 |
| `ik_header_after` | 前台 header 后 |
| `ik_footer_before` | 前台 footer 前 |
| `ik_footer_scripts` | 前台 `</body>` 前 |
| `ik_admin_head` | 后台 `</head>` 前 |
| `ik_admin_footer_scripts` | 后台 `</body>` 前 |

---

## 安全机制

- CSRF Token 保护所有 POST 请求
- XSS 过滤 — 输出转义（`htmlspecialchars`）
- SQL 注入防护 — PDO 预处理语句
- 密码加密 — bcrypt 哈希
- 登录限流 — 5 次失败后锁定 15 分钟
- 会话安全 — HttpOnly、SameSite=Lax、30 分钟超时
- 文件上传 — 扩展名白名单 + 大小限制（10MB）+ 自动重命名
- 敏感目录 — .htaccess / Nginx 规则禁止直接访问

---

## 相关链接

- 官网：[https://www.yikaicms.com](https://www.yikaicms.com)

## 许可证

Yikai CMS - 企业内容管理系统
