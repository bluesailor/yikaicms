# 演示站公共悬浮栏

`demo-rail.js` 是 `demos.yikai` 演示宿主的公共脚本，不属于可导入的主题或 CMS 安装包。它通过 Shadow DOM 隔离样式，按页面语言显示中文、英文或日文，并在移动端收为按钮。当前提供模板库、复制链接、回到顶部三个动作。

部署到演示宿主时，将 `demo-rail.js` 和 `catalog-dispatch.php` 放在演示目录的 `_shared/` 下。把 `nginx-http.conf` 的内容写入 Nginx `http` 块中的**用户维护配置**，测试语法后重载。易开面板环境使用 `D:\yikai\config\custom-nginx.conf`；不要编辑面板生成的 `panel-nginx.conf` 或 `panel-rewrite-*.conf`。Nginx 必须包含 `http_sub_module`。脚本只在 `demos.yikai` 主机注入，且只对 `/yikai-*/` 前台页面挂载 UI，后台等路径会跳过。

如果目录站的 Nginx `try_files` 把子站伪静态 URL 回退到根 `index.php`，需在根目录页的 `declare(strict_types=1);` 后、目录页函数定义前加载：

```php
require __DIR__ . '/_shared/catalog-dispatch.php';
```

这样 `/yikai-business/about.html` 和 `/yikai-business/en/` 仍由各自 CMS 处理，根 `/` 继续显示模板目录。发布到其他宿主前，先确认其回退规则和 `_shared` 静态文件的访问权限。
