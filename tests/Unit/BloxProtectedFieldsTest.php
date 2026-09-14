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
