# 子目录部署

适用于把 YikaiCMS 放在某个目录下访问，例如 `https://demo.yikaicms.com/yikai-zhuangshi.yikai/`。
放在域名根目录的站点不需要看本文，行为与以前完全相同。

## 自动完成的部分

站点能识别自己被放在哪个目录下，**不需要任何配置**：拷进哪个目录就认哪个。

- 首页与所有漂亮网址正常路由；
- 页面里的链接、图片、样式、脚本、表单地址自动带上目录前缀；
- 页面跳转自动带上目录前缀；
- 规范地址（canonical）、网站地图、多语言 hreflang 自动带上目录前缀。

站内保存的内容（正文里的图片、区块里的链接）**不需要改**，输出时自动补前缀。

## nginx 配置

**同一域名下放多个站点**（每个一级子目录一个站，比如演示站批量放模板）：直接用
`deploy/nginx-subdirectories.conf`，放进 `server { … }` 一次即可，之后新增站点只需把程序放进新的
一级目录，不用再改 nginx；保护规则、安装锁、伪静态都按目录名自动套用。

**只放一个子目录站**，或站点嵌套在多级目录下（如 `/a/b/`）：用下面这段单站写法。

把下面整段放进站点的 `server { … }` 里，并把所有 `/sub` 替换成实际目录名
（与磁盘上的目录名一致）；`fastcgi_pass` 按你的 PHP-FPM 实际地址修改。

> ⚠ **保护规则必须带目录前缀。** 根目录版 `nginx-server.conf` 的保护规则锚定在根路径，
> 站点放进子目录后一条都匹配不上，`config/`、`storage/`（含 SQLite 数据库）会直接暴露。
> 不要把根目录版原样套用到子目录站点上。

```nginx
# ── YikaiCMS 子目录部署（把 /sub 全部替换成实际目录名，与磁盘上的目录名一致）──
#
# 为什么每条 deny 都要带 /sub 前缀：
#   根目录版 nginx-server.conf 的保护规则锚定在根路径（^~ /config/、^/(uploads|storage)/）。
#   站点放进子目录后路径变成 /sub/config/…，那些规则一条都匹配不上，
#   config/、storage/（含 SQLite 数据库）就会直接暴露。
#
# 为什么没有逐条的 rewrite：
#   漂亮网址全部由 PHP 的 Dispatcher 路由；根目录版里那串 rewrite 只是「直接派发到入口文件」的优化，
#   子目录下不需要。一条 try_files 交给 /sub/index.php 即可。
location ^~ /sub/ {
    # 默认首页自己声明：面板把 index.html 排在前面时，/sub/ 与 /sub/admin/ 仍进 PHP
    index index.php index.html;

    # ── 保护规则：必须写在 \.php$ 之前（nginx 正则 location 按出现顺序，先匹配先生效）──
    location ~ ^/sub/\.                                                   { deny all; }
    location ~ ^/sub/(config|storage|deploy|vendor|includes|bin|migrations|recipes)/ { deny all; }
    location ~ ^/sub/install/sql/                                         { deny all; }
    location ~ ^/sub/(uploads|storage)/.*\.(php|phtml|phar|php[0-9])$     { deny all; }
    location ~* ^/sub/.*\.(md|sql|bak|example|dist|conf|lock|yml|yaml)$   { deny all; }
    location ~ ^/sub/(composer\.(json|lock)|package(-lock)?\.json)$       { deny all; }

    # 安装完成（存在 installed.lock）后关闭安装器
    location ^~ /sub/install/ {
        if (-f $document_root/sub/installed.lock) { return 403; }
        location ~ ^/sub/install/index\.php$ {
            # 请求被嵌套 location 接走后，父 location 里的 if 不再执行——锁检查必须在这里再写一次
            if (-f $document_root/sub/installed.lock) { return 403; }
            fastcgi_pass   127.0.0.1:9000;
            fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include        fastcgi_params;
        }
        location ~ \.php$ { deny all; }
    }

    location ~ \.php$ {
        fastcgi_pass   127.0.0.1:9000;
        fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include        fastcgi_params;
    }

    try_files $uri $uri/ /sub/index.php?$query_string;
}
```

这份配置已在 nginx 1.15 + PHP 8.2 上实测：页面、资源、跳转、多语言前缀正常；
下列 20 项访问全部被拒绝：`config/`、`storage/`（含数据库文件）、`includes/`、`vendor/`、
`deploy/`、`migrations/`、`bin/`、`recipes/`、`install/sql/`、已安装后的 `install/`（含 `install/index.php`）、
`composer.json`、`package.json`、`*.md`、`.htaccess`、`.gitignore`、`installed.lock`，
以及 `uploads/`、`storage/` 下的 PHP 文件（不会被执行）。

不需要逐条写漂亮网址的 `rewrite`：它们全部由 PHP 路由处理。根目录版里那一串 `rewrite`
只是直接派发到入口文件的性能优化。

## 后台「站点URL」

后台「基本设置 → 站点信息 → 站点URL」连目录一起填，例如：

```
https://demo.yikaicms.com/yikai-zhuangshi.yikai
```

它只影响**绝对地址**（规范地址、网站地图、分享卡片、邮件里的链接）。站内链接不依赖它。

设置页会显示「当前访问地址」并可一键填入；填的地址与实际访问地址对不上时会给出提醒。
**如果填的是制作时的本机地址**（`127.0.0.1`、`localhost`、`*.test`、`*.yikai` 等）
而站点已经在公网访问，设置页与站点体检都会给出红色警告——那样规范地址、网站地图和分享卡片
会全部指向外人打不开的地址，而页面本身看起来一切正常。从本机拷站点上线后请务必检查这一项。

## 反向代理

如果站点经反向代理挂在某个路径下，而 PHP 看到的是根路径（例如对外是 `/blog/`、
后端收到的是 `/`），自动识别无法得知对外的目录。此时在 `config/config.php` 里显式指定：

```php
define('SITE_BASE_PATH', '/blog');
```

## 给主题和插件开发者

- 在 PHP 模板里照常写根相对地址（`/product.html`、`/uploads/x.jpg`），输出时会自动补前缀。
- **静态 JS 文件**里写死的地址不会被改写。请读取 `window.YK_BASE`：

  ```js
  fetch((window.YK_BASE || '') + '/form_submit.php', { method: 'POST', body })
  ```

  只在子目录部署时存在；根目录下为 `undefined`，按上面的写法取空串即可。
- PHP 生成的内联脚本请用 `BasePath::url('/path')`。
- **存进数据库的地址不要带前缀**（`/uploads/x.jpg`，不是 `/sub/uploads/x.jpg`）：前台输出时会统一补，
  带了就会变成 `/sub/sub/…`。脚本里只在「写进 href/src、发请求、跳转」的那一刻补前缀。
- 脚本按数据渲染的 `<img>`（媒体库网格、编辑器缩略图等）忘了补前缀时，页面注入的兜底脚本会在
  加载失败后自动补上重试一次——只认 `/uploads/`、`/assets/`、`/plugins/`、`/themes/`，
  这是容错，不是约定，新代码请照上面的写法。

## 目前的限制

- 安装器、前台、后台（含可视化编辑器与前台编辑模式）都已支持子目录部署。
  直接访问 `/sub/install/` 安装即可，站点地址会自动带上目录。
- **`robots.txt` 只在域名根目录生效**：爬虫不会读 `/sub/robots.txt`。需要的屏蔽规则
  （如 `Disallow: /sub/admin/`）和 `Sitemap:` 行请加到域名根目录的 `robots.txt` 里。
  后台「SEO 设置 → Robots.txt」在子目录部署时会给出同样的提示。
- **Apache 暂不支持子目录部署**：`.htaccess` 的改写规则目标是根路径、且写死了
  `RewriteBase /`，放进子目录后请求会被改写到域名根目录。请使用 nginx。
- 预生成的静态 HTML 在子目录下由 PHP 直出，而不是 nginx 直出（功能正常，略慢）。
