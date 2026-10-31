# AI 外部接入前的核心能力审计

`ai-access-baseline.php` 是 S1-01 的行为探针，不属于普通的“全部应为绿”的 PHPUnit 套件。它执行真实核心能力的 preview/execute，记录业务表变化，并报告当前边界缺口。不得把已观察到的缺口固化为产品应该允许的行为。

在仓库根目录、全新 PHP CLI 进程执行：

```powershell
php tests/audit/ai-access-baseline.php
$LASTEXITCODE
```

需要 PHP 8.2+、PDO SQLite 与已有 Composer 开发依赖。无需 `config/config.php`、站点数据库、后台登录或供应商密钥。输出为 JSON；如需保存，保存到 web 根目录以外的证据目录。

退出码：

| 退出码 | 含义 |
| --- | --- |
| 0 | 已覆盖当前核心目录，未观察到这些探针所检查的缺口；**不代表已满足外部接入门槛** |
| 1 | 审计不完整、能力执行失败、缺少测试案例或对照检查失败，需要先修探针/补覆盖 |
| 2 | 审计完成，但发现边界缺口；当前基线的预期结果 |

`external_access_ready` 固定为 false。本脚本没有验证真实身份、令牌、协议、MySQL 或插件，更没有实现这些功能，不能为部署签发“可开放”结论。`negative_probes` 的 true 表示观察到了所命名行为，不表示安全检查通过；`controls` 的 true 才表示该项对照检查通过。

## 隔离和覆盖

- 仅 CLI 可执行，HTTP 请求返回 404 并立即退出。
- 拒绝继承应用配置常量；加载测试 bootstrap 后确认 `sqlite::memory:`，只创建合成的 contents/channels/settings 三张表。
- 没有加载产品 init、私有配置、真实认证、插件、业务钩子、缓存、定时发布或网络客户端。
- 用测试身份替代 `hasPermission`，用计数桩替代 `aiService` 和 `adminLog`。这不证明生产身份刷新、日志、授权服务可用。
- 每项能力重新建夹具；preview 和 execute 分别比较表快照。未来若回调开始依赖更多业务表，必须扩展夹具和快照；当前失败不能忽略。
- 14 个明确命名的核心用例，新增/移除未同步的核心能力会使覆盖失败。第三方能力不在加载范围内，始终未审计。
- 10 个读取/输入负例和 14 个对照检查。对照覆盖匿名/无权限/错误模块写入、类型/必填/枚举、未知能力、供应商失败无写入和无 PHP session 启动。
- Schema 的 additionalProperties、长度和数值下界探针直接调用现有私有 validator，因为当前注册的核心 schema 没有声明所有这些约束。它证明 validator 不执行这些规则，不证明每个具体端点接受了同样输入。
- 缺失栏目探针的 SQLite 夹具没有外键；结论是 handler 没有业务级栏目校验，不能据此断言所有生产数据库都会成功插入孤立记录。
- 默认 bootstrap 的多语言开关为 false；跨语言负例结合直接 SQL 源码确认缺少语言条件，不代替真实多语言站点验收。

详细矩阵、设计决策和后续验收见内部文档库 `ai-access/S1-01-ability-audit-2026-10-30.md`。本审计不修改产品运行时代码。
