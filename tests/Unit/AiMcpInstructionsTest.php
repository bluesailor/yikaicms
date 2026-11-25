<?php
/** MCP 连接时下发的上手规则（2.3.1）：覆盖每一种提案工具，新旧两版协议用同一份文字。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AiMcpServer;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ai-access/AiMcpServer.php';

final class AiMcpInstructionsTest extends TestCase
{
    public function testInstructionsNameEveryProposalTool(): void
    {
        $tools = (string) file_get_contents(ROOT_PATH . '/includes/ai-access/AiMcpTools.php');
        preg_match_all("/'([a-z_]+_propose)' => \\['title'/", $tools, $matches);
        self::assertGreaterThanOrEqual(3, count($matches[1]), '文章、单页、产品三种提案工具');
        foreach ($matches[1] as $tool) {
            self::assertStringContainsString($tool, AiMcpServer::INSTRUCTIONS, "上手规则要写到 {$tool}");
        }
        self::assertStringNotContainsString('YikaiCMS article drafts:', AiMcpServer::INSTRUCTIONS, '不再只说文章草稿');
        self::assertStringContainsString('base_revision', AiMcpServer::INSTRUCTIONS);
    }

    public function testBothProtocolGenerationsSendTheSameInstructions(): void
    {
        $server = (string) file_get_contents(ROOT_PATH . '/includes/ai-access/AiMcpServer.php');
        self::assertSame(2, substr_count($server, "'instructions' => self::INSTRUCTIONS"), 'server/discover 与旧版 initialize 都用这一份');
    }
}
