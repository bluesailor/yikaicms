# YikaiCMS 开发备忘

## Tailwind CSS 编译

使用独立 EXE 编译，无需 npm：

```bash
/mnt/d/phpstudy_pro/WWW/tailwindcss-windows-x64.exe -i assets/css/src/app.css -o assets/css/tailwind.css --minify
```

源文件：`assets/css/src/app.css`（Tailwind CSS v4，扫描 PHP 文件自动提取 class）

修改 PHP 模板中的 Tailwind class 后需要重新编译 CSS。
