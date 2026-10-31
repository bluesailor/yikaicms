<?php
/**
 * AI 外部访问入口（MCP，Streamable HTTP）。客户端地址：https://<站点>/mcp/（子目录站：https://<站点>/<子目录>/mcp/）。
 * 默认关闭：站长在后台启用外部访问并签发令牌后才可用。不加载后台、不启动 session。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/ai-access/AiMcpEntry.php';
AiMcpEntry::run(dirname(__DIR__));
