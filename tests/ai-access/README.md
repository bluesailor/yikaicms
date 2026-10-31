# AI 外部访问：可复跑隔离验证

对应内部文档库 `ai-access/ADR-002-proposal-transactions.md`。测试只操作**合成测试库**；S3 起直接执行产品迁移，入口套件调用实际 MCP 入口。不是客户站部署，不读写真实站点、真实令牌或客户库。真实模型脚本需显式选择并预先在隔离站配置测试 API。

| 文件 | 作用 |
| --- | --- |
| `proposal-fixture.php` | 连接独立测试库（SQLite 文件名须 `s103-*`，MySQL 库名须 `s103_*`）；核心表取自 `install/sql`，提案/令牌/扩展日志为预建原型表；合成种子数据 |
| `proposal-txn-run.php` | 14 个场景、139 条断言；每个场景重建测试库；结束时删除全部测试表 |
| `proposal-worker.php` | 并发子进程：独立连接，按检查点持锁/发信号，确定性地制造“谁先取锁” |
| `run-local-mysql8.ps1` | Windows：用本机 PHP 8.2 与 MySQL 8.0 起一个**独立的临时 mysqld**（自有数据目录/端口/空 root），跑完即关停删除 |
| `../Unit/AiProposalTransactionTest.php` | PHPUnit 包装：总是跑 SQLite；设置了 `YK_S103_MYSQL_NAME` 时再跑 MySQL/MariaDB |

## 运行

```bash
# SQLite（任意 PHP 8.2+，需 pdo_sqlite、mbstring）
YK_S103_DRIVER=sqlite YK_S103_SQLITE_PATH=/tmp/s103-run.sqlite php tests/ai-access/proposal-txn-run.php --out=report-sqlite.json

# MySQL / MariaDB：先建一个只用于测试的空库（名称必须以 s103_ 开头）
YK_S103_DRIVER=mysql YK_S103_MYSQL_HOST=127.0.0.1 YK_S103_MYSQL_PORT=3306 YK_S103_MYSQL_NAME=s103_txn_test \
YK_S103_MYSQL_USER=<测试账号> YK_S103_MYSQL_PASSWORD=<测试口令> php tests/ai-access/proposal-txn-run.php --out=report-mysql.json

# Windows 本机（PHP 8.2 + MySQL 8.0 临时实例）
powershell -ExecutionPolicy Bypass -File tests\ai-access\run-local-mysql8.ps1 -Php <php.exe> -MysqlHome <mysql 安装目录>
```

`YK_S103_ONLY=06` 只跑名称含该片段的场景。退出码 0 = 全部通过，1 = 有失败断言。报告 JSON 记录驱动、服务器版本、PHP 版本和每条断言。

## 场景与交接要求的对应

| 场景 | 覆盖 |
| --- | --- |
| 01 | 业务 INSERT/UPDATE 失败、日志 INSERT 失败、状态写后失败均全回滚；嵌套事务拒绝；去除故障后可重试 |
| 02 | 4 进程同键同载荷并发只建一份提案；同键异载荷 409；无键拒绝；3 进程并发应用只建一篇草稿 |
| 03 | 内部来源命名空间 `internal`、token_id=0，无 NULL；与令牌命名空间/其他 owner 隔离 |
| 04 | 重复应用不写；提交后回调失败返回 `applied + post_commit_error`，重试读回同一结果 |
| 05 | 部分选择：未选项保留，单项冲突不清空整组 |
| 06 | 撤销令牌/禁用/降权与应用的两种先后，均以锁检查点判定；撤销先提交时提案也不能写入；总开关/演示/过期 |
| 07 | 人工编辑/发布/删除/换类型/换语言/换栏目，栏目停用/改语言/改类型/语言停用 → conflicted 且不改动；与人工保存并发 |
| 08 | 撤销仅在版本未变时执行；他人不可撤销；新建撤销为软删除；发布/继续编辑后拒绝；撤销中日志失败整体回滚 |
| 09 | 令牌撤销后待审项不能应用，owner 仍可撤销已应用改动；owner 失去 edit_article 后不能撤销 |
| 10 | 应用结果均 status=0/publish_time=0；已发布内容、设置、栏目字节不变；HTML 净化；错误/日志/子进程输出无令牌明文或摘要、无正文 |
| 11 | 输入白名单与目标政策负例（S1-01 A04/A05）；他人提案不可见 |

断言是否有效，用“故意改坏实现”验证过（执行报告的变异测试表）：去掉行锁、去掉事务、去掉来源重验、去掉撤销版本检查、去掉唯一索引、去掉提交后回读，都会让对应断言失败。

## S1-04：MCP 协议层与真实客户端

对应内部文档库 `ai-access/ADR-003-mcp-protocol-and-clients.md`。同样只用 `s103-*` 合成库，不是产品入口。

| 文件 | 作用 |
| --- | --- |
| `mcp-dev-seed.php` | 重建合成库，签发编辑令牌（29，六个工具）与只读令牌（39，四个读取工具），明文只写入 `--token-dir`；`--pages` 另建三张合成单页与单页只读令牌（79，site_info + page_read，写入 pager.token），供 S5-02 真实客户端验证；`--revoke=<id>` 吊销 |
| `mcp-dev-router.php` | `php -S 127.0.0.1:<port>` 的隔离入口（路径 `/mcp`）；`YK_S104_TRACE` 写协议追踪，不含凭据、正文、参数 |
| `mcp-protocol-run.php` | 8 个场景、75 条 HTTP 断言，自起服务，不调用模型 |
| `../Unit/AiMcpProtocolTest.php` | PHPUnit 包装 |

```bash
YK_S103_DRIVER=sqlite YK_S103_SQLITE_PATH=/tmp/s103-mcp-run.sqlite php tests/ai-access/mcp-protocol-run.php --out=report-protocol.json
```

真实客户端（Claude Code、Codex）的连接步骤见内部文档库 `ai-access/S1-04-mcp-client-verification-2026-10-31.md`。

## S2：令牌、正式入口、限流

| 文件 | 作用 |
| --- | --- |
| `throttle-run.php` | 限流计数的多进程并发：全新桶、多个进程同时起跑，检查计数无丢失、放行数精确；另有 6 进程并发签发同一账号，有效令牌恰好 10 枚（SQLite / MySQL，连接方式同 S1-03） |
| `mcp-entry-run.php` | 正式入口端到端：在系统临时目录搭合成的已安装站（安装模板 config、完整安装 SQL、准备好的桥接），经 `/mcp/` 验证认证、HTTPS/可信代理、限流、审计、故障拒绝；`--apache=<httpd.exe>` 另起临时 Apache（mod_fcgid，站点在 `/sub/`）验证改写与 Authorization 传递 |
| `../Unit/AiAccessTokenStoreTest.php` | 令牌仓储、限流、审计的单元测试 |

```bash
php tests/ai-access/mcp-entry-run.php
php tests/ai-access/mcp-entry-run.php --apache=<Apache 安装目录>/bin/httpd.exe
```

`run-local-mysql8.ps1` 同时跑 S1-03 提案事务与限流并发（MySQL 8.0 / 5.7 均可）。临时站点的 `includes/` 是指回仓库的目录联接，脚本清理时先拆联接再删目录；手工清理时也必须如此，不要递归删除联接。

## S3：提案正式迁移与后台审核

| 文件 | 内容 |
| --- | --- |
| `proposal-fixture.php` | 提案/令牌/日志表直接执行正式迁移建表（与安装包同源），不再是原型 schema |
| `proposal-txn-run.php` 场景 12 | 拒绝、每账号待审配额（含两个并发提案抢最后一个名额）、定时清理、`enabled_languages` 为空的老站 |
| `mcp-entry-run.php` 场景 07 | 经正式入口提交提案、同键重放、只读令牌看不到提案工具、应用后查到草稿 ID、提案额度独立于读取额度 |
| `../Unit/AiProposalReviewTest.php`、`../Unit/AiProposalMigrationTest.php` | 审核页段落对照；旧结构 `ai_change_log` 的升级补列 |

MySQL 上的提案套件仍用 `run-local-mysql8.ps1`（自动包含场景 12 与并发签发）。后台审核页的端到端走查与真实客户端记录在内部文档库 `ai-access/S3-proposals-verification-2026-10-31.md`。

## S4：环境、升级与发布边界

- `nginx-entry-run.php --nginx=<nginx.exe> --out=<report.json>`：独立回环 nginx、PHP FastCGI 与验证证书的 HTTPS。临时证书不加入系统信任库，服务结束后清理。
- `mcp-entry-run.php --apache=<httpd.exe>`：包含 180 KB 正文进入提案、应用为草稿和超限 413，不再跳过 Apache 大请求。
- `upgrade-run.php`：沿用 `YK_S103_*` 合成库参数，模拟旧日志表升级、重复迁移、产品 SQL 备份恢复和再次迁移。MySQL 本地脚本也会自动执行。
- `admin-smoke-run.php`：先在独立工作区执行 `tests/smoke/setup.php`，再运行本脚本，结束后执行 `setup.php --restore`。脚本自己管理回环服务的生命周期。
- `assistant-model-run.php --base=http://127.0.0.1:<port> --out=<report.json>`：显式真实模型调用；必须先在隔离 smoke 站配置测试 API。仅开放标签能力，用真实模型产生暂存，再验证应用与撤销，内容始终是草稿。它不自动读取或搬运其他站点的密钥，不纳入默认 PHPUnit。
- `assistant-seo-model-run.php --base=http://127.0.0.1:<port> --out=<report.json>`：同样是显式真实模型调用，覆盖 SEO 摘要能力：对话阶段生成并暂存固定摘要、对话不改文章、应用写入的正是暂存摘要且仍为草稿、撤销恢复、正文被人工改过后旧暂存拒绝写入。

新增场景 14 验证满额/目标变化后的幂等重试，以及来源撤销后不能重放。`AiProposalTransactionTest` 同时包装 SQLite 升级/备份恢复演练。发行包契约要求 MCP 全部运行时文件，并拒绝私有数据库桥接文件。
