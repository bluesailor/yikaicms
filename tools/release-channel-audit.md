# 发布渠道检查

```sh
php tools/release-channel-audit.php 1.19.9 --candidate
php tools/release-channel-audit.php 1.19.9 --post-release --online --json=storage/channel-audit.json
```

candidate 用于已经生成完整包、校验文件和构建证据之后的候选检查。线上未同步可以跳过；归档损坏或本地渠道目录配置错误不能豁免。

post-release 中，任何 failed 或 skipped 都使退出码为 1。本地文件齐全只证明准备完成，不能证明线上同步。线上检查包括：

- 官网三语首页、三语更新日志的正文版本及实际下载链接；忽略注释、脚本、导航和页脚中的版本引用。
- 升级服务器完整包、delta 的实际下载 SHA-256，以及目标发布条目的线上元数据。
- 市场公开 API 中的主题清单、版本、签名元数据、下载 URL，以及包 SHA-256。
- GitHub Release API 中的正式发布状态、标签对应提交、完整包与校验附件的身份和下载 SHA-256。

网络取数使用 HTTPS GET，禁止降级重定向。每次下载落入系统临时文件后计算 SHA-256 并清理，单项最多 256 MiB/120 秒；元数据正文读取上限 512 KiB，超限/截断不会放行。配置在 `config/release-channels.php`。

## 升级服务器只读元数据

目前 `update.yikaicms` 的 `data/` 目录受访问保护，不能为了此检查开放目录，也不能调用会登记安装信息的 `/api/update/check.php`。

`YK_RELEASE_CATALOG_URL` 可指定运维专用的 HTTPS **只读**元数据入口。响应必须包含 `latest` 和 `releases` 数组；目标条目与本地 `data/releases.json` 的对应条目逐字段一致，包括包身份、哈希、PHP 要求和 delta 列表。不要在 URL 中放凭据，检查结果会记录证据 URL；不要将整个受保护目录或含未公开策略的目录数据直接公开。

未配置时，工具仍核对线上完整包和 delta，但目录同步记为 skipped，发布后整体失败。这是缺少证据，不是判断线上包损坏。配置该入口之前必须先确认其访问控制与无写入副作用；本次 CMS 工具修复不创建或部署新的服务器入口。

## 验证

```sh
php vendor/phpunit/phpunit/phpunit --filter ReleaseChannelAudit --do-not-cache-result
```

测试使用隔离 fixture 和可记录请求的 fetcher，包含旧官网、注释中的目标版本、坏 delta、目录错误、缺线上证据、远端包错误、下架主题回潮、GitHub 假 HTML/草稿/附件缺失等反例，不需要真实发布或生产登录。
