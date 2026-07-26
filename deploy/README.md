# 部署配置

不同 Web 环境的伪静态（URL 重写）配置。选你的环境照做即可。

| 环境 | 用哪个文件 | 一句话 |
|---|---|---|
| 宝塔面板（nginx） | `deploy/nginx-baota.conf` | 粘进伪静态框，或 include 一次 |
| 阿里云虚拟主机 / 万网（Apache 共享主机） | `deploy/aliyun-vhost.htaccess` 或根目录 `.htaccess` | 重命名放根目录 |
| 阿里云虚拟主机（nginx 型） | `deploy/aliyun-nginx.htaccess` | 面板伪静态处使用（仅支持有限指令） |
| 自己的 nginx 服务器（完整 server 块） | `deploy/nginx-server.conf` | 加进 server 块，带静态直出 |
| Apache（自己的服务器 / phpStudy） | 根目录 `.htaccess` | 开 mod_rewrite 即用 |

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
