<?php
declare(strict_types=1);
namespace Yikai\Tests\Unit;
use Yikai\Tests\TestCase;
require_once ROOT_PATH . '/admin/includes/website_pages.php';

final class WebsitePagesTest extends TestCase
{
    public function testHomeTitleFollowsContentLanguage(): void
    {
        $this->assertSame('Home', websiteHomeTitle('en'));
        $this->assertSame('ホーム', websiteHomeTitle('ja'));
        $this->assertSame('网站首页', websiteHomeTitle('zh-CN'));
        $this->assertSame(websiteHomeTitle('zh-CN'), websiteHomeTitle('../config/config'));
    }

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE channels (id INTEGER PRIMARY KEY, name TEXT, type TEXT, parent_id INTEGER DEFAULT 0, lang TEXT, status INTEGER, sort_order INTEGER DEFAULT 0, updated_at INTEGER)',
            'CREATE TABLE contents (id INTEGER PRIMARY KEY, channel_id INTEGER, lang TEXT, updated_at INTEGER, deleted_at INTEGER)',
        ];
    }

    public function testCatalogueScopesLanguageAndKeepsDisabledAlbumsWithoutDuplicates(): void
    {
        foreach ([[1,'Parent','page',0,'en',1],[2,'Album','album',1,'en',0],[3,'Article','list',0,'en',1],[4,'Other','page',0,'ja',1]] as $row) {
            db()->execute('INSERT INTO channels(id,name,type,parent_id,lang,status) VALUES(?,?,?,?,?,?)', $row);
        }
        db()->execute('INSERT INTO contents VALUES(1,1,?,100,NULL)', ['en']);
        db()->execute('INSERT INTO contents VALUES(2,1,?,200,NULL)', ['en']);
        db()->execute('INSERT INTO contents VALUES(3,1,?,900,1)', ['en']);
        db()->execute('INSERT INTO contents VALUES(4,1,?,800,NULL)', ['ja']);
        $rows = channelModel()->websitePages('en');
        $this->assertSame([1,2], array_map('intval', array_column($rows, 'id')));
        $this->assertSame('Parent', $rows[1]['parent_name']);
        $this->assertSame(200, (int) $rows[0]['content_updated_at']);
        $this->assertSame(0, (int) $rows[1]['status']);
    }

    public function testPublicationFilterDoesNotConfuseVisibilityWithDraftState(): void
    {
        $rows = [
            ['id'=>1,'name'=>'About','public_url'=>'/about','parent_name'=>'Company','status'=>1,'publication'=>'published'],
            ['id'=>2,'name'=>'Campaign','public_url'=>'/campaign','parent_name'=>'','status'=>0,'publication'=>'changed'],
            ['id'=>3,'name'=>'New','public_url'=>'/new','parent_name'=>'','status'=>1,'publication'=>'draft'],
        ];
        $this->assertSame([2], array_column(websitePagesFilter($rows,'','changed'),'id'));
        $this->assertSame([2], array_column(websitePagesFilter($rows,'','disabled'),'id'));
        $this->assertSame([3], array_column(websitePagesFilter($rows,'','draft'),'id'));
        $this->assertSame([1], array_column(websitePagesFilter($rows,' COMPANY ','active'),'id'));
        $this->assertSame([], websitePagesFilter($rows,'not-found','all'));
    }
}
