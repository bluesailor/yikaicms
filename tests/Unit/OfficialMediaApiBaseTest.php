<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 官方素材 API 地址的 host 白名单。
 *
 * 起因：apiBase() 原来的判据只有 `^https?://`——既不限 host，也放行明文 http。
 * 而这个值来自数据库设置 official_media_api_base，任何能写设置的人
 * （后台账号、或一份外来配方）都能把请求连同授权信息引到自己的服务器上。
 */
final class OfficialMediaApiBaseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/RemoteOfficialMedia.php';
    }

    /** @return list<array{0:string}> */
    public static function rejectedProvider(): array
    {
        return [
            ['http://update.yikaicms.com/api/media'],       // 明文降级
            ['https://evil.example.com/api/media'],         // 换 host
            ['http://evil.example.com/api/media'],
            ['https://update.yikaicms.com.evil.com/api'],   // 子域后缀绕过
            ['https://update.yikaicms.com@evil.com/api'],   // userinfo 绕过
            ['//evil.com/api'],
            ['javascript:alert(1)'],
            ['ftp://update.yikaicms.com/api'],
            [''],
            // 以下由合并后采用的更严判据额外挡住
            ['https://update.yikaicms.com:8443/api/media'],      // 额外端口
            ['https://update.yikaicms.com/api/media?x=1'],       // query
            ['https://update.yikaicms.com/api/media#f'],         // fragment
            ['https://update.yikaicms.com/api/media/../../etc'], // 路径穿越
            ['https://update.yikaicms.com/api/other'],           // 路径必须精确匹配
            ['http://127.0.0.1:8080/api/media'],                 // 回环未开开发开关
            ['http://localhost/api/media'],                      // 回环只认 127.0.0.1
        ];
    }

    /** @dataProvider rejectedProvider */
    public function testHostileBasesFallBackToOfficial(string $candidate): void
    {
        self::assertSame(
            'https://update.yikaicms.com/api/media',
            RemoteOfficialMedia::normalizeApiBase($candidate),
            "「{$candidate}」必须退回官方默认地址。"
        );
    }

    public function testOfficialHostOverHttpsIsAccepted(): void
    {
        self::assertSame(
            'https://update.yikaicms.com/api/media',
            RemoteOfficialMedia::normalizeApiBase('https://update.yikaicms.com/api/media/')
        );
        // DNS 不区分大小写，大写 host 同样是官方站
        self::assertSame(
            'https://UPDATE.YIKAICMS.COM/api/media',
            RemoteOfficialMedia::normalizeApiBase('https://UPDATE.YIKAICMS.COM/api/media')
        );
    }

    /**
     * 回环地址要显式开发开关才放行。
     *
     * 为什么不能默认放行：素材列表与解析请求会带上 license_key() 与 license_domain()，
     * 指向本机的另一个服务同样等于把授权信息交出去。
     */
    public function testLoopbackRequiresAnExplicitDevelopmentFlag(): void
    {
        $original = getenv('YIKAI_OFFICIAL_MEDIA_ALLOW_LOCAL');
        try {
            putenv('YIKAI_OFFICIAL_MEDIA_ALLOW_LOCAL');
            self::assertSame(
                'https://update.yikaicms.com/api/media',
                RemoteOfficialMedia::normalizeApiBase('http://127.0.0.1:8080/api/media'),
                '没有开发开关时回环地址必须退回官方默认值。'
            );

            putenv('YIKAI_OFFICIAL_MEDIA_ALLOW_LOCAL=1');
            self::assertSame(
                'http://127.0.0.1:8080/api/media',
                RemoteOfficialMedia::normalizeApiBase('http://127.0.0.1:8080/api/media'),
                '显式开启开发开关后，本机素材服务应可联调。'
            );
        } finally {
            // 不要把环境变量泄漏给后续用例
            if ($original === false) {
                putenv('YIKAI_OFFICIAL_MEDIA_ALLOW_LOCAL');
            } else {
                putenv('YIKAI_OFFICIAL_MEDIA_ALLOW_LOCAL=' . $original);
            }
        }
    }
}
