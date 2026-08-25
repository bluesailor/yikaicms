# 部署配置

不同 Web 环境的伪静态（URL 重写）配置。选你的环境照做即可。

> **v1.12.9 起支持 WordPress 兼容模式**：主机面板选「WordPress 伪静态」预设即可运行
> （程序内置 Dispatcher 完成分发），或用 `deploy/htaccess-minimal.txt`。
> 有条件仍推荐完整规则（静态直出性能最好 + 服务器层安全拦截）。

| 环境 | 用哪个文件 | 一句话 |
|---|---|---|
| 任何支持 WordPress 的主机 | 面板选「WordPress 伪静态」或 `deploy/htaccess-minimal.txt` | 两行 catch-all，程序内分发 |
| 宝塔面板（nginx） | 伪静态选「wordpress」预设，或 `deploy/nginx-baota.conf` | 选预设最省事；conf 版含静态直出 |
| 阿里云虚拟主机 / 万网（Apache 共享主机） | `deploy/aliyun-vhost.htaccess` 或根目录 `.htaccess` | 重命名放根目录 |
| 阿里云虚拟主机（nginx 型） | `deploy/aliyun-nginx.htaccess` | 面板伪静态处使用（仅支持有限指令） |
| 自己的 nginx 服务器（完整 server 块） | `deploy/nginx-server.conf` | 加进 server 块，带静态直出 |
| Apache（自己的服务器 / phpStudy） | 根目录 `.htaccess` | 开 mod_rewrite 即用 |

---

## 上线后的安全复测

部署或调整 Web 服务器规则后，到后台 **系统 → 站点健康** 重新扫描。重点确认：

- 配置目录、运行数据目录和程序目录的探测请求返回 403 或 404；
- `uploads/` 下的 PHP 探针返回 403 或 404；
- 如果探针正文被原样返回，虽然没有执行 PHP，仍存在源码泄露，应继续封禁；
- 如果探针返回执行标记，说明上传目录仍会执行 PHP，必须先修服务器配置再上线。

Nginx 配置修改后先执行 `nginx -t`，确认语法通过再重载，然后回到站点健康复测。
不要只凭“配置文件里写过规则”判断安全：虚拟主机可能不加载该文件，正则 `location`
的顺序也可能让通用 PHP 处理规则抢先命中。

### 不要使用空 location 块

下面这种写法**不是封禁**：

```nginx
location ^~ /uploads/ {
}
```

空块没有 `deny` 或 `return`。带 `^~` 时还会阻止后面的 PHP 正则处理，可能把 PHP
源码当静态文件返回；不带 `^~` 时，通用 `location ~ \.php$` 又可能继续执行它。
请直接采用本目录对应环境的完整规则。自管 Nginx 至少应保留：

```nginx
location ~ ^/(uploads|storage)/.*\.(php|phtml|phar|php[0-9])$ {
    deny all;
}
```

这条正则必须放在通用 `location ~ \.php$` 之前。宝塔伪静态框不要添加 `location`，
使用 `deploy/nginx-baota.conf` 中的 server 级 `if ... return 403`，避免与面板已有块冲突。

---

## 一、宝塔面板（nginx）

nginx 没有 Apache 那种每目录 `.htaccess`，规则要写进站点配置。但可以**配置一次、以后自动更新**：

**推荐做法（一次配置，永久生效）**
宝塔 → 网站 → 设置 → **伪静态**，框里只写一行：

```nginx
include /www/wwwroot/你的站点目录/deploy/nginx-baota.conf;
```

保存。以后 CMS 升级包会更新 `deploy/nginx-baota.conf`，你只需在宝塔点一下「重载配置」，新路由即生效——**再也不用进伪静态框粘贴**。

**或者**：直接把 `deploy/nginx-baota.conf` 的**全部内容**粘进伪静态框（升级后路由有变时需再粘一次）。

> 为什么能 include：宝塔的「伪静态」本质就是一个被 nginx `include` 进 server 块的文件，所以里面再 include 一个我们自己的文件完全合法。
>
> 这个文件只含 `rewrite`、不含任何 `location` 块，因此不会和宝塔自带的 `location /`、`location ~ \.php$` 冲突（那正是不能直接用`deploy/nginx-server.conf` 的原因——它含 location 块，会「duplicate location」报错）。
>
> 当前版本还在文件开头使用 server 级 `if` 封禁 `config/`、`storage/`、`vendor/`、`includes/`、`install/sql/`、`bin/`、`migrations/`、`recipes/`。`install/index.php` 会在根目录 `installed.lock` 存在后自行拒绝访问；拥有完整 server 配置权限时，应优先使用 `deploy/nginx-server.conf`，由 Nginx 在 PHP 之前封禁整个 `install/`。

**老站 301 跳转**（迁移旧链接保 SEO）是每个站自己的，不在通用文件里。如需，把 `rewrite ^/旧路径$ /新路径 permanent;` 加在 `nginx-baota.conf` **最上面**（permanent 会中断后续规则）。

---

## 二、阿里云虚拟主机 / 万网（Apache 共享主机）

这类主机支持 `.htaccess`，开箱即用：

1. 把整站文件上传到网站根目录（`htdocs/` 或面板指定目录）。
2. 确认根目录有 `.htaccess`（项目自带）。若面板会覆盖根目录，就用 `deploy/aliyun-vhost.htaccess`，**重命名为 `.htaccess`** 放根目录。
3. `storage/`、`uploads/` 需**可写**（755/775）。

**上传后 500 怎么办**（共享主机常见）：
- 多半是主机不允许 `.htaccess` 里的 `php_value`。把 `.htaccess` 末尾 `<IfModule mod_php.c> … </IfModule>` 整段删掉，PHP 的上传大小等改到**主机控制面板**里设。
- 目录浏览的 `Options -Indexes` 已包在 `<IfModule mod_autoindex.c>` 里，通常不会再 500。

---

## 三、完整 nginx server 块（含静态 HTML 直出）

如果你有服务器 root 权限、想要**静态化直出**（后台「静态生成」后 `.html` 由 nginx 直接返回、不进 PHP，性能最好），用`deploy/nginx-server.conf`：把它的内容合并进你的 `server { }` 块。它包含 `try_files /html$uri …` 的静态优先逻辑。

> 宝塔的伪静态框不适合放这个（含 location 块会冲突）。要在宝塔用静态直出，去「配置文件」里改完整 server 块，而不是伪静态框。

---

## 定时任务（所有环境通用）

后台的「定时内容上线、回收站清理、自动备份」需要一个定时触发器。到服务器 crontab 或宝塔「计划任务」加一条（token 在后台「系统 → 定时任务」页看）：

```
*/5 * * * * curl -s "https://你的域名/cron.php?token=后台给的token" >/dev/null
```

没有 cron 的共享主机：定时发布会在有访问时兜底触发；备份/清理建议手动或用主机自带的计划任务。

## 万网 / 阿里云云虚拟主机（NGINX）

| 文件 | 用途 |
|---|---|
| `aliyun-nginx-minimal.txt` | **推荐**。极简版：敏感目录拦截 + 不存在的文件交给 index.php，由内置路由分发器接管 |
| `aliyun-nginx.htaccess` | 全量显式 rewrite 规则版，需要逐条控制时使用 |

粘贴位置：主机控制面板 → 高级环境设置 → NGINX 设置，替换掉面板默认的 `location / {}` 与 `location ~ /\.ht {deny all;}`。
