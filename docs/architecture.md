# Yikai CMS 架构文档

版本：v1.3 | 更新日期：2026-03-26

---

## 一、系统概述

Yikai CMS 是一款基于 PHP 8.0+ 的轻量级企业内容管理系统，无框架依赖，开箱即用。

### 技术栈

| 层级 | 技术 |
|------|------|
| 后端 | PHP 8.0+，PDO（MySQL 5.7+ / SQLite 3） |
| 前端 CSS | Tailwind CSS v4 |
| 前端 JS | Alpine.js v3 |
| 富文本编辑器 | wangEditor v5 |
| 轮播图 | Swiper |
| 拖拽排序 | SortableJS |
| 滚动动画 | AOS |
| 拼音转换 | overtrue/pinyin v6 |

### 目录结构

```
├── admin/                  后台管理页面
│   └── includes/           后台公共文件（header/footer/auth）
├── api/                    API 接口
├── assets/                 前端资源（CSS/JS/第三方库）
├── config/                 配置文件
│   ├── config.php          主配置（数据库、路径、安全）
│   ├── database.php        Database 类
│   └── defaults.php        设置默认值
├── includes/               核心代码
│   ├── models/             数据模型（26个）
│   ├── blocks/             首页区块模板（7个）
│   ├── functions.php       公共函数库
│   ├── hooks.php           钩子系统
│   ├── plugin.php          插件加载器
│   ├── init.php            引导文件
│   ├── header.php          前台头部
│   └── footer.php          前台底部
├── install/                安装向导 + SQL 文件
├── lang/                   语言包
├── member/                 前台会员页面
├── plugins/                插件目录
├── storage/                运行时数据（日志/缓存/限流）
├── themes/                 主题目录
├── uploads/                用户上传文件
└── vendor/                 Composer 依赖
```

---

## 二、数据库架构

共 26 张表，前缀 `yikai_`。

### 2.1 栏目与内容系统（核心）

#### yikai_channels — 栏目表

网站导航结构的核心，定义所有频道/栏目。

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| parent_id | INT | 父栏目ID，0=顶级 |
| name | VARCHAR(50) | 栏目名称 |
| slug | VARCHAR(50) | URL别名（唯一） |
| type | VARCHAR(20) | 栏目类型（见下表） |
| content | LONGTEXT | 单页内容（type=page时使用） |
| image | VARCHAR(255) | 栏目图片 |
| description | TEXT | 栏目描述 |
| link_url | VARCHAR(255) | 外链地址（type=link时使用） |
| link_target | VARCHAR(10) | 外链打开方式 |
| redirect_type | VARCHAR(20) | 跳转方式：auto/url/none |
| redirect_url | VARCHAR(255) | 跳转地址 |
| album_id | INT | 关联相册ID（type=album时使用） |
| seo_title | VARCHAR(255) | SEO标题 |
| seo_keywords | VARCHAR(255) | SEO关键词 |
| seo_description | VARCHAR(500) | SEO描述 |
| is_nav | TINYINT | 是否显示在导航 |
| is_home | TINYINT | 是否在首页展示 |
| is_system | TINYINT | 是否系统预置（不可删除） |
| status | TINYINT | 状态：0禁用 1启用 |
| sort_order | INT | 排序 |

**栏目类型说明：**

| type 值 | 说明 | 内容来源 | 前台路由 |
|---------|------|----------|---------|
| list | 文章列表 | yikai_contents | list.php |
| page | 单页 | channels.content 或 yikai_contents | page.php |
| product | 产品中心 | yikai_products | product.php |
| case | 案例展示 | yikai_contents | detail.php |
| download | 下载中心 | yikai_downloads | detail.php |
| job | 招聘 | yikai_jobs | job_detail.php |
| album | 相册 | yikai_albums | page.php |
| link | 外链 | 无（跳转） | 直接跳转 |

#### yikai_contents — 统一内容表

存储所有通过栏目管理的内容（文章、案例、FAQ等）。

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| channel_id | INT | 所属栏目ID |
| type | VARCHAR(20) | 内容类型：article/case/download/job |
| title | VARCHAR(255) | 标题 |
| subtitle | VARCHAR(255) | 副标题 |
| slug | VARCHAR(255) | URL别名 |
| cover | VARCHAR(255) | 封面图 |
| images | TEXT | 图片组（JSON） |
| summary | TEXT | 摘要 |
| content | LONGTEXT | 正文内容 |
| author | VARCHAR(50) | 作者 |
| source | VARCHAR(100) | 来源 |
| tags | VARCHAR(255) | 标签（逗号分隔） |
| attachment | VARCHAR(255) | 附件路径 |
| download_count | INT | 下载次数 |
| price | DECIMAL(10,2) | 价格 |
| specs | TEXT | 规格参数（JSON） |
| location | VARCHAR(100) | 工作地点（招聘用） |
| salary | VARCHAR(50) | 薪资范围（招聘用） |
| requirements | TEXT | 任职要求（招聘用） |
| headcount | VARCHAR(20) | 招聘人数 |
| job_type | VARCHAR(20) | 工作性质 |
| education | VARCHAR(50) | 学历要求 |
| experience | VARCHAR(50) | 经验要求 |
| is_top | TINYINT | 置顶 |
| is_recommend | TINYINT | 推荐 |
| is_hot | TINYINT | 热门 |
| views | INT | 浏览量 |
| likes | INT | 点赞数 |
| seo_title | VARCHAR(255) | SEO标题 |
| seo_keywords | VARCHAR(255) | SEO关键词 |
| seo_description | VARCHAR(500) | SEO描述 |
| status | TINYINT | 状态：0草稿 1发布 2归档 |
| publish_time | INT | 发布时间（UNIX时间戳） |
| created_at | INT | 创建时间 |
| updated_at | INT | 更新时间 |
| admin_id | INT | 创建人ID |

**索引：**
- idx_channel (channel_id)
- idx_type (type)
- idx_status (status)
- idx_top (is_top)
- idx_recommend (is_recommend)
- ft_search FULLTEXT (title, summary)

### 2.2 产品系统

#### yikai_products — 产品表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| category_id | INT | 产品分类ID |
| title | VARCHAR(255) | 产品名称 |
| subtitle | VARCHAR(255) | 副标题 |
| slug | VARCHAR(255) | URL别名 |
| cover | VARCHAR(255) | 封面图 |
| images | TEXT | 产品图片组（JSON） |
| summary | TEXT | 简介 |
| content | LONGTEXT | 详情 |
| price | DECIMAL(10,2) | 价格 |
| market_price | DECIMAL(10,2) | 市场价 |
| model | VARCHAR(100) | 型号 |
| specs | TEXT | 规格参数（JSON） |
| tags | VARCHAR(255) | 标签 |
| is_top, is_recommend, is_hot, is_new | TINYINT | 标记位 |
| views | INT | 浏览量 |
| status | TINYINT | 状态 |
| sort_order | INT | 排序 |
| created_at, updated_at | INT | 时间戳 |
| admin_id | INT | 创建人 |

#### yikai_product_categories — 产品分类表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| parent_id | INT | 父分类ID |
| name | VARCHAR(50) | 分类名称 |
| slug | VARCHAR(50) | URL别名 |
| image | VARCHAR(255) | 分类图片 |
| is_nav | TINYINT | 是否显示在导航 |
| description | TEXT | 描述 |
| seo_title, seo_keywords, seo_description | VARCHAR | SEO字段 |
| status | TINYINT | 状态 |
| sort_order | INT | 排序 |

### 2.3 专用内容表

#### yikai_jobs — 招聘职位表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| title | VARCHAR(255) | 职位名称 |
| location | VARCHAR(100) | 工作地点 |
| salary | VARCHAR(50) | 薪资范围 |
| job_type | VARCHAR(20) | 工作性质（全职/兼职） |
| education | VARCHAR(50) | 学历要求 |
| experience | VARCHAR(50) | 经验要求 |
| headcount | VARCHAR(20) | 招聘人数 |
| content | LONGTEXT | 职位描述 |
| requirements | TEXT | 任职要求 |
| views | INT | 浏览量 |
| is_top | TINYINT | 置顶 |
| status | TINYINT | 状态 |
| sort_order | INT | 排序 |
| created_at, updated_at | INT | 时间戳 |

#### yikai_downloads — 下载文件表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| category_id | INT | 下载分类ID |
| title | VARCHAR(255) | 文件名称 |
| description | TEXT | 文件描述 |
| file_url | VARCHAR(500) | 文件路径/外链 |
| file_name | VARCHAR(255) | 原始文件名 |
| file_size | BIGINT | 文件大小 |
| file_ext | VARCHAR(10) | 文件扩展名 |
| download_count | INT | 下载次数 |
| is_external | TINYINT | 是否外链 |
| require_login | TINYINT | 是否需要登录 |
| status | TINYINT | 状态 |
| sort_order | INT | 排序 |
| created_at, updated_at | INT | 时间戳 |

#### yikai_download_categories — 下载分类表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| name | VARCHAR(50) | 分类名称 |
| description | VARCHAR(255) | 描述 |
| sort_order | INT | 排序 |
| status | TINYINT | 状态 |

### 2.4 媒体与展示

#### yikai_media — 媒体库

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| name | VARCHAR(255) | 原始文件名 |
| path | VARCHAR(500) | 服务器路径 |
| url | VARCHAR(500) | 访问URL |
| type | VARCHAR(20) | 类型：image/file/video |
| ext | VARCHAR(10) | 扩展名 |
| mime | VARCHAR(100) | MIME类型 |
| size | INT | 文件大小 |
| width, height | INT | 图片尺寸 |
| md5 | CHAR(32) | 文件MD5（去重） |
| admin_id | INT | 上传人 |
| created_at | INT | 上传时间 |

#### yikai_albums — 相册表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| category_id | INT | 分类ID |
| name | VARCHAR(100) | 相册名称 |
| slug | VARCHAR(100) | URL别名 |
| cover | VARCHAR(255) | 封面图 |
| description | TEXT | 描述 |
| photo_count | INT | 照片数量 |
| status | TINYINT | 状态 |
| sort_order | INT | 排序 |

#### yikai_album_photos — 相册照片表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| album_id | INT | 所属相册ID |
| title | VARCHAR(255) | 照片标题 |
| image | VARCHAR(500) | 图片路径 |
| thumb | VARCHAR(500) | 缩略图路径 |
| description | TEXT | 描述 |
| sort_order | INT | 排序 |
| status | TINYINT | 状态 |

#### yikai_banners — 轮播图表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| group_id | INT | 分组ID |
| position | VARCHAR(20) | 位置标识 |
| title | VARCHAR(100) | 标题 |
| subtitle | VARCHAR(255) | 副标题 |
| image | VARCHAR(255) | PC端图片 |
| image_mobile | VARCHAR(255) | 移动端图片 |
| link_url | VARCHAR(500) | 链接地址 |
| btn_text, btn_url | VARCHAR | 按钮文字和链接 |
| btn2_text, btn2_url | VARCHAR | 第二按钮 |
| start_time, end_time | INT | 定时展示时间范围 |
| sort_order | INT | 排序 |
| status | TINYINT | 状态 |

#### yikai_banner_groups — 轮播图分组表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| name | VARCHAR(50) | 分组名称 |
| slug | VARCHAR(50) | 短码标识（如 home/about） |
| height_pc | SMALLINT | PC端高度(px) |
| height_mobile | SMALLINT | 移动端高度(px) |
| autoplay_delay | INT | 自动播放间隔(ms) |

#### yikai_links — 友情链接表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| name | VARCHAR(100) | 链接名称 |
| url | VARCHAR(500) | 链接地址 |
| logo | VARCHAR(255) | Logo图片 |
| description | VARCHAR(255) | 描述 |
| status | TINYINT | 状态 |
| sort_order | INT | 排序 |

#### yikai_timelines — 发展历程表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| year | SMALLINT | 年份 |
| month | TINYINT | 月份 |
| day | TINYINT | 日 |
| title | VARCHAR(255) | 事件标题 |
| content | TEXT | 事件描述 |
| image | VARCHAR(255) | 图片 |
| icon | VARCHAR(50) | 图标 |
| color | VARCHAR(20) | 颜色 |
| sort_order | INT | 排序 |
| status | TINYINT | 状态 |

### 2.5 互动功能

#### yikai_forms — 表单提交记录

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| type | VARCHAR(50) | 表单类型（模板slug） |
| name | VARCHAR(100) | 姓名 |
| phone | VARCHAR(20) | 电话 |
| email | VARCHAR(100) | 邮箱 |
| company | VARCHAR(100) | 公司 |
| content | TEXT | 内容 |
| extra | TEXT | 全部字段数据（JSON） |
| ip | VARCHAR(45) | 提交IP |
| user_agent | VARCHAR(500) | 浏览器 |
| status | TINYINT | 状态：0未处理 1已处理 |
| follow_admin | VARCHAR(50) | 跟进人 |
| follow_note | TEXT | 跟进备注 |
| created_at | INT | 提交时间 |

#### yikai_form_templates — 表单模板

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| name | VARCHAR(100) | 模板名称 |
| slug | VARCHAR(50) | 短码标识（用于 [form-slug] 嵌入） |
| fields | TEXT | 字段定义（JSON或CF7格式） |
| success_message | VARCHAR(255) | 提交成功提示语 |
| status | TINYINT | 状态 |

#### yikai_members — 前台会员表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| username | VARCHAR(50) | 用户名 |
| password | VARCHAR(255) | 密码（bcrypt） |
| email | VARCHAR(100) | 邮箱 |
| nickname | VARCHAR(50) | 昵称 |
| avatar | VARCHAR(255) | 头像 |
| status | TINYINT | 状态 |
| last_login_time | INT | 最后登录时间 |
| last_login_ip | VARCHAR(45) | 最后登录IP |
| login_count | INT | 登录次数 |
| created_at | INT | 注册时间 |

### 2.6 系统管理

#### yikai_users — 管理员表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| username | VARCHAR(50) | 用户名 |
| password | VARCHAR(255) | 密码（bcrypt） |
| nickname | VARCHAR(50) | 昵称 |
| email | VARCHAR(100) | 邮箱 |
| avatar | VARCHAR(255) | 头像 |
| role_id | INT | 角色ID |
| status | TINYINT | 状态 |
| last_login_time | INT | 最后登录时间 |
| last_login_ip | VARCHAR(45) | 最后登录IP |
| login_count | INT | 登录次数 |

#### yikai_roles — 角色权限表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| name | VARCHAR(50) | 角色名称 |
| description | VARCHAR(255) | 描述 |
| permissions | TEXT | 权限列表（JSON数组） |
| status | TINYINT | 状态 |

**权限标识：** `*`(超级管理员), `content`, `product`, `media`, `form`, `member`, `setting`, `system`

#### yikai_admin_logs — 操作日志表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| admin_id | INT | 管理员ID（0=未登录） |
| admin_name | VARCHAR(50) | 管理员用户名 |
| module | VARCHAR(50) | 模块（auth/article/product/setting等） |
| action | VARCHAR(50) | 操作（login/create/update/delete等） |
| description | VARCHAR(500) | 操作描述 |
| url | VARCHAR(500) | 请求URL |
| method | VARCHAR(10) | 请求方法 |
| request_data | TEXT | 请求数据（JSON） |
| ip | VARCHAR(45) | 操作IP |
| user_agent | VARCHAR(500) | 浏览器 |
| created_at | INT | 操作时间 |

#### yikai_settings — 系统设置表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| group | VARCHAR(20) | 分组：basic/contact/email/home/header/footer/code/member |
| key | VARCHAR(50) | 设置键名（唯一） |
| value | TEXT | 设置值 |
| type | VARCHAR(20) | 字段类型：text/textarea/number/select/image/editor/color/code |
| name | VARCHAR(100) | 显示名称 |
| tip | VARCHAR(255) | 帮助提示 |
| options | TEXT | 选项（JSON，用于select类型） |
| sort_order | INT | 排序 |

#### yikai_plugins — 插件注册表

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INT PK | 主键 |
| slug | VARCHAR(50) | 插件标识 |
| status | TINYINT | 状态：0禁用 1启用 |
| installed_at | INT | 安装时间 |
| activated_at | INT | 启用时间 |

### 2.7 历史遗留表（数据已迁移）

| 表名 | 说明 | 状态 |
|------|------|------|
| yikai_articles | 原文章表 | 数据已迁入 yikai_contents |
| yikai_article_categories | 原文章分类表 | 已迁为 yikai_channels 子栏目 |

---

## 三、预置栏目结构

系统安装后预置 18 个栏目：

```
关于我们 (about) ── type: page
├── 公司简介 (company) ── type: page
├── 企业文化 (culture) ── type: page
├── 发展历程 (history) ── type: page
└── 荣誉资质 (honor) ── type: album

产品中心 (product) ── type: product [首页展示]

解决方案 (solution) ── type: case [首页展示]
├── 行业方案 (industry) ── type: case
└── 成功案例 (cases) ── type: case

新闻资讯 (news) ── type: list [首页展示]
├── 公司新闻 (company-news) ── type: list
└── 行业动态 (industry-news) ── type: list

服务支持 (service) ── type: page
├── 服务流程 (process) ── type: page
├── 常见问题 (faq) ── type: list
└── 下载中心 (download) ── type: download

人才招聘 (job) ── type: job
联系我们 (contact) ── type: page
隐私政策 (privacy) ── type: page [不在导航]
服务条款 (terms) ── type: page [不在导航]
```

---

## 四、URL 路由规则

通过 .htaccess 伪静态实现 SEO 友好 URL。

### 新闻文章

| URL | 实际路由 | 说明 |
|-----|---------|------|
| /news.html | news.php | 新闻列表 |
| /news/page/2.html | news.php?page=2 | 分页 |
| /news/company-news.html | news.php?cat=company-news | 分类筛选 |
| /news/article/1.html | article.php?id=1 | 文章详情（ID） |
| /news/article/my-slug.html | article.php?slug=my-slug | 文章详情（slug） |

### 产品中心

| URL | 实际路由 | 说明 |
|-----|---------|------|
| /product/1.html | product.php?id=1 | 产品详情（ID） |
| /product/cat/slug.html | product.php?slug=slug | 产品详情（slug） |
| /product/category.html | list.php?slug=product&cat=category | 产品分类列表 |

### 栏目页面

| URL | 实际路由 | 说明 |
|-----|---------|------|
| /about.html | page.php?slug=about | 单页 |
| /about/company.html | page.php?parent=about&slug=company | 子页面 |
| /list/1.html | list.php?id=1 | 栏目列表（ID） |
| /case/1.html | detail.php?id=1 | 案例详情 |
| /job/1.html | job_detail.php?id=1 | 招聘详情 |
| /download/detail/1.html | detail.php?id=1 | 下载详情 |

### 特殊页面

| URL | 实际路由 | 说明 |
|-----|---------|------|
| /contact.html | contact.php | 联系我们 |
| /about/history.html | history.php | 发展历程 |
| /sitemap.xml | sitemap.php | XML网站地图 |

---

## 五、模型层

所有模型继承 `Model` 基类，提供统一 CRUD 操作。

### 基类方法（Model.php）

| 方法 | 说明 |
|------|------|
| find($id) | 按主键查 |
| findBy($column, $value) | 按字段查 |
| findWhere($conditions) | 按条件查 |
| where($conditions) | 查多条 |
| all() | 查全部 |
| count($conditions) | 计数 |
| create($data) | 新增 |
| updateById($id, $data) | 更新 |
| deleteById($id) | 删除 |
| deleteByIds($ids) | 批量删除 |
| toggle($id, $field) | 切换0/1 |
| increment($id, $field) | 自增 |

### 模型清单

| 模型 | 表 | 用途 |
|------|-----|------|
| ChannelModel | channels | 栏目管理 |
| ContentModel | contents | 统一内容 |
| ProductModel | products | 产品管理 |
| ProductCategoryModel | product_categories | 产品分类 |
| JobModel | jobs | 招聘管理 |
| DownloadModel | downloads | 下载管理 |
| DownloadCategoryModel | download_categories | 下载分类 |
| AlbumModel | albums | 相册管理 |
| AlbumPhotoModel | album_photos | 相册照片 |
| MediaModel | media | 媒体库 |
| BannerModel | banners | 轮播图 |
| BannerGroupModel | banner_groups | 轮播图分组 |
| LinkModel | links | 友情链接 |
| TimelineModel | timelines | 发展历程 |
| FormModel | forms | 表单提交 |
| FormTemplateModel | form_templates | 表单模板 |
| UserModel | users | 管理员 |
| RoleModel | roles | 角色权限 |
| MemberModel | members | 前台会员 |
| AdminLogModel | admin_logs | 操作日志 |
| SettingModel | settings | 系统设置 |
| PluginModel | plugins | 插件管理 |
| ArticleModel | articles | 旧文章（已弃用） |
| ArticleCategoryModel | article_categories | 旧文章分类（已弃用） |

---

## 六、设置系统

通过 `config($key, $default)` 函数全局访问。

### 设置分组

| 分组 | 说明 | 配置页面 |
|------|------|---------|
| basic | 站点名称、Logo、favicon、URL | admin/setting.php |
| header | 页头布局、颜色、粘性 | admin/setting.php?tab=header |
| footer | 页脚布局、栏目、版权 | admin/setting.php?tab=footer |
| code | 自定义头部/底部代码 | admin/setting.php?tab=code |
| contact | 联系电话、邮箱、地址、地图 | admin/setting_contact.php |
| email | SMTP、发件人、通知 | admin/setting_email.php |
| home | 首页区块配置 | admin/setting_home.php |
| member | 会员注册、登录设置 | admin/setting_member.php |

### 新增设置页面

| 页面 | 说明 |
|------|------|
| admin/setting_seo.php | SEO设置（5个Tab：基础/社交分享/站长验证/Sitemap/Robots.txt） |
| admin/setting_security.php | 安全设置（4个Tab：登录安全/登录记录/上传安全/日志管理） |

---

## 七、插件系统

WordPress 风格的 Hook 系统。

### API

```php
// 注册动作
add_action('hook_name', callable $callback, int $priority = 10);
// 触发动作
do_action('hook_name', ...$args);
// 注册过滤器
add_filter('hook_name', callable $callback, int $priority = 10);
// 应用过滤器
$value = apply_filters('hook_name', $value, ...$args);
```

### 内置钩子

| 钩子 | 触发位置 | 用途 |
|------|---------|------|
| plugins_loaded | init.php | 所有插件加载完成 |
| ik_head | header.php `<head>` | 注入头部代码 |
| ik_header_after | header.php | 导航栏后 |
| ik_footer_before | footer.php | 页脚前 |

### 内置插件

| 插件 | 说明 |
|------|------|
| back-to-top | 返回顶部按钮 |
| db-backup | 数据库备份导出 |
| menu-sort | 栏目拖拽排序 |
| search-replace | 全局搜索替换 |
| sql-runner | SQL 执行器（管理员） |

### 插件开发

在 `/plugins/my-plugin/` 下创建：
- `plugin.json` — 元信息（name, version, author, description, require_php, require_cms）
- `main.php` — 入口文件，通过 add_action/add_filter 注册功能

---

## 八、安全机制

| 防护 | 实现方式 |
|------|---------|
| SQL 注入 | PDO 预处理语句 |
| XSS | htmlspecialchars 转义 + sanitizeHtml 富文本净化 |
| CSRF | Token 验证（POST 字段或 X-CSRF-TOKEN 头） |
| 暴力破解 | 登录失败限流（可配置次数和时长） |
| 文件上传 | 扩展名白名单 + MIME验证 + getimagesize验证 + 随机重命名 |
| Session | HttpOnly + SameSite=Lax + 可配置超时 |
| 目录保护 | .htaccess 禁止访问敏感目录 |
| 点击劫持 | X-Frame-Options: SAMEORIGIN |
| 表单防刷 | IP + 时间窗口限流（可配置） |

---

## 九、性能优化

| 优化项 | 说明 |
|--------|------|
| 文件缓存 | cacheGet/cacheSet/cacheDelete/cacheClear |
| FULLTEXT 搜索 | MySQL 环境使用 MATCH AGAINST 替代 LIKE |
| WebP 转换 | 图片上传自动生成 WebP 副本 |
| 图片懒加载 | 前台所有 img 标签 loading="lazy" |
| 浏览器缓存 | 图片1年/CSS JS 1个月/immutable |
| GZIP 压缩 | HTML/CSS/JS/SVG/字体 |
| Sitemap 缓存 | XML 网站地图缓存（可配置TTL） |
| 设置缓存 | SettingModel 内存缓存（单次请求） |
