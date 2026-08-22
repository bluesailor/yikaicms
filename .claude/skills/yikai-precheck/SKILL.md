---
name: yikai-precheck
description: YikaiCMS 改完代码要并入 main 之前跑的红线预检。当你准备提交、合并到主树、或用户说「可以了/提交吧/并进去」时使用。也用于排查「本地绿了 CI 却红」这类问题。
---

# YikaiCMS 并 main 前预检

**红线：五项全绿才允许把工作分支并入 main，缺一不可。CI 是兜底，不是第一道测试。**

这条红线不是流程洁癖，是五起真事故换来的（每条下面都记了由来）。

## 执行

```bash
bash tools/premerge-check.sh          # 自动判断要不要起服务器跑冒烟
bash tools/premerge-check.sh --full   # 强制全跑
bash tools/premerge-check.sh --quick  # 只跑单测 + Psalm + i18n
```

脚本负责**可靠执行**（清端口、起服务器、保证还原）；下面这些**判断**归你，脚本代替不了。

## 判断一：Psalm 报错先分清真假

本项目 Psalm 缓存会对**确实存在的类**误报 `UndefinedClass`（今天出现 4 次以上）。
脚本已经自动「清缓存后复验」，但你看到报错时仍要记住：**清缓存前的结果不算数**。

```bash
php vendor/vimeo/psalm/psalm --clear-cache && php vendor/vimeo/psalm/psalm --no-progress
```

## 判断二：本地 Psalm 永远比 CI 宽松

CI 上**没有 `config/config.php`**（它不入库）。所以任何**独立入口**——自己
`define('ROOT_PATH')` 再 `require config/config.php` 的文件，如 `admin/*.php`、
`plugins/*/xxx_api.php`——在本地永远绿，只有 CI 会报 `MissingFile`。

**新增这类文件时，必须同步加进 `psalm.xml` 的 `MissingFile` 豁免。**
2026-08-22 这个坑踩了两次（`plugins/seo/links_api.php`、`admin/nav_menu.php`）。

## 判断三：什么时候必须跑后台页面冒烟

**改过任何 `admin/` 下的页面就必须跑**，脚本会自动检测并执行。

由来：`setting_email.php` 用 `'163'`/`'126'` 当数组键，PHP 自动转 int，`e(int)` 撞
`?string` 签名 → 整页 500，**穿过了 php -l / 单测 / Psalm / CRUD 冒烟四层**带病进了 main。
页面渲染期的错误，只有真把页面渲染一遍才发现。

## 判断四：脚本测不了、只能人工的三件

1. **前台/主题输出改动** → 在本树 vhost（`http://yikaicms.claude.yikai/`）人工过一遍受影响页面
2. **Tailwind class 改动** → 重编 CSS 并 `grep` 产物确认新类真的进去了：
   ```bash
   /mnt/d/phpstudy_pro/WWW/tailwindcss-windows-x64.exe -i assets/css/src/app.css -o assets/css/tailwind.css --minify
   grep -cF 'peer-checked\:translate-x-4' assets/css/tailwind.css   # 转义类名要用 -F
   ```
   由来：`min-h-0`/`pb-16` 没进产物，元素库从不滚动，带病发布两版。
3. **提交前看 `git status`** → 三个 worktree 共用一个 git，别把别人的在建改动带上

## 提交与合并

```bash
# 逐文件 add —— 禁止 git add -A
git add <明确列出的文件>
git commit -m "..."
cd /mnt/d/phpstudy_pro/WWW/yikaicms.yikai && git merge claude/work && git push origin main
```

`git add -A` 的禁令来自 2026-08-05 的事故：把付费源码和**含 DB_PASS / ENCRYPT_KEY
的 config 备份**推上了公开仓库。

合并后盯 CI：

```bash
gh run list --branch main --limit 1
gh run watch <id> --exit-status
```

**注意**：连续推送时前一轮 CI 会被并发组取消，显示 `cancelled` 而不是失败——
看到 cancelled 先确认是不是被自己顶掉的，别当成红灯排查半天。

## 常见执行陷阱（都是今天真踩的）

| 现象 | 原因 | 处理 |
|---|---|---|
| `Could not open input file: tests/...` | 工作目录漂移（上一条命令里 `cd` 过） | 命令开头显式 `cd /mnt/d/phpstudy_pro/WWW/yikaicms.claude.yikai` |
| 冒烟登录失败、结果全假 | 8080 上有残留 `php -S`，旧进程拿旧库应答 | 脚本已自动清；手工跑时先 `netstat` 查 PID 再 taskkill |
| 工作树被留在冒烟配置上 | 忘了 `setup.php --restore` | 脚本保证执行；手工跑时**失败也要还原** |
| lint 临时文件报「文件不存在」 | WSL 的 `php` 是 Windows php.exe，**看不到 /tmp** | 临时文件放项目树内（如 `storage/`），用完删 |
| i18n 门禁报中文回潮，但那是日志文本 | 门禁扫**全部** PHP 字符串，日志/注记也算 | 改英文或走 lang 键；语义词典类数据放 `includes/` 顶层（见 `BloxNavIconMatcher` 先例） |

## 相关

- 完整开发规约：`AGENTS.md` §14（本 skill 是它的可执行化）
- **发版**（不是并 main）另有一套：`bash tools/release-precheck.sh <版本号>`，
  规程见 `yikaicms-docs/release-process.md`
