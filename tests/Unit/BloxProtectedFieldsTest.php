<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxProtectedFields.php';

final class BloxProtectedFieldsTest extends TestCase
{
    private function document(): array
    {
        return [['id' => 's1', 'columns' => [['id' => 'c1', 'elements' => [
            ['id' => 'e1', 'type' => 'heading', 'data' => ['text' => 'Old', 'site_field' => 'site_name']],
        ]]]]];
    }

    public function testOrdinaryEditsPreserveProtectedBindingsWithoutMutatingInput(): void
    {
        $trusted = $this->document();
        $next = $trusted;
        $next[0]['columns'][0]['elements'][0]['data']['text'] = 'New';
        $validation = BloxProtectedFields::forValidation($next, $trusted, ['query_loop']);
        self::assertSame('New', $validation[0]['columns'][0]['elements'][0]['data']['text']);
        self::assertArrayNotHasKey('site_field', $validation[0]['columns'][0]['elements'][0]['data']);
        self::assertSame('site_name', $next[0]['columns'][0]['elements'][0]['data']['site_field']);
    }

    public function testNewOrdinaryElementsCanReceiveIdsLater(): void
    {
        $trusted = $this->document();
        $next = $trusted;
        $next[0]['columns'][0]['elements'][] = ['type' => 'text', 'data' => ['content' => 'New']];
        self::assertCount(2, BloxProtectedFields::forValidation($next, $trusted, ['query_loop'])[0]['columns'][0]['elements']);
        $next[0]['columns'][0]['elements'][1]['data']['site_field'] = 'site_name';
        $this->expectException(RuntimeException::class);
        BloxProtectedFields::forValidation($next, $trusted, ['query_loop']);
    }

    private function loopDocument(): array
    {
        return [['id' => 's1', 'columns' => [['id' => 'c1', 'elements' => [
            ['id' => 'loop', 'type' => 'list-dynamic', 'data' => ['source' => 'article', 'pagination_mode' => 'numbers', 'children' => [
                ['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'Title', 'loop_field' => 'title']],
                ['id' => 't1', 'type' => 'text', 'data' => ['html' => '<p>Intro</p>', 'loop_fallback' => 'None']],
            ]]],
        ]]]]];
    }

    public function testLoopTemplateChildrenAllowOrdinaryEditsButFreezeStructureAndBindings(): void
    {
        $trusted = $this->loopDocument();
        $next = $trusted;
        $next[0]['columns'][0]['elements'][0]['data']['children'][1]['data']['html'] = '<p>Changed</p>';
        $next[0]['columns'][0]['elements'][0]['data']['children'][1]['data']['loop_fallback'] = 'Empty';
        $next[0]['columns'][0]['elements'][0]['data']['children'][0]['data']['text'] = 'Shown when unbound';
        $validation = BloxProtectedFields::forValidation($next, $trusted, ['query_loop']);
        // 校验副本不再携带模板子树，原始提交保持不变。
        self::assertArrayNotHasKey('children', $validation[0]['columns'][0]['elements'][0]['data']);
        self::assertCount(2, $next[0]['columns'][0]['elements'][0]['data']['children']);

        foreach (['binding', 'add', 'reorder', 'retype', 'pagination'] as $mode) {
            $changed = $trusted;
            $children = &$changed[0]['columns'][0]['elements'][0]['data']['children'];
            switch ($mode) {
                case 'binding': $children[0]['data']['loop_field'] = 'summary'; break;
                case 'add': $children[] = ['id' => 'b1', 'type' => 'button', 'data' => ['text' => 'More']]; break;
                case 'reorder': $children = array_reverse($children); break;
                case 'retype': $children[1]['type'] = 'heading'; break;
                case 'pagination': $changed[0]['columns'][0]['elements'][0]['data']['pagination_mode'] = 'none'; break;
            }
            unset($children);
            try {
                BloxProtectedFields::forValidation($changed, $trusted, ['query_loop']);
                self::fail('Accepted loop change: ' . $mode);
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testProtectedFieldsCannotBeChangedDroppedCopiedOrMoved(): void
    {
        foreach (['change', 'drop', 'copy', 'move', 'type', 'duplicate_id'] as $mode) {
            $trusted = $this->document();
            $next = $trusted;
            switch ($mode) {
                case 'change': $next[0]['columns'][0]['elements'][0]['data']['site_field'] = 'contact_email'; break;
                case 'drop': unset($next[0]['columns'][0]['elements'][0]['data']['site_field']); break;
                case 'copy': $copy = $next[0]['columns'][0]['elements'][0]; $copy['id'] = 'e2'; $next[0]['columns'][0]['elements'][] = $copy; break;
                case 'move': $next[0]['columns'][0]['id'] = 'c2'; break;
                case 'type': $next[0]['columns'][0]['elements'][0]['type'] = 'text'; break;
                case 'duplicate_id': $next[0]['columns'][0]['elements'][] = $next[0]['columns'][0]['elements'][0]; break;
            }
            try {
                BloxProtectedFields::forValidation($next, $trusted, ['query_loop']);
                self::fail('Accepted ' . $mode);
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
}
