<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/hooks.php';

final class SettingEventsTest extends TestCase
{
    private array $actions;
    private array $events = [];

    protected function schemaSql(): array
    {
        return ['CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE,
            `value` TEXT CHECK (`value` <> \'rejected\'), `group` TEXT, name TEXT, tip TEXT, type TEXT)'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        settingModel()->clearCache();
        $this->actions = $GLOBALS['ik_actions'] ?? [];
        $GLOBALS['ik_actions'] = [];
        add_action('data_changed', function (string $table): void { $this->events[] = ['data', $table]; });
        add_action('setting_saved', function (array $values): void {
            $this->events[] = ['settings', $values, settingModel()->getAll()];
        });
    }

    protected function tearDown(): void
    {
        $GLOBALS['ik_actions'] = $this->actions;
        settingModel()->clearCache();
        parent::tearDown();
    }

    public function testInsertAndUpdateNotifyOnceAndListenersSeeNewValues(): void
    {
        settingModel()->set('site_name', 'before');
        self::assertSame('before', settingModel()->get('site_name'));
        $this->events = [];
        settingModel()->set('site_name', 'after');
        self::assertSame([['data', 'settings'], ['settings', ['site_name' => 'after'], ['site_name' => 'after']]], $this->events);
    }

    public function testBatchNotifiesOnceForBothInsertsAndUpdates(): void
    {
        settingModel()->set('site_name', 'old');
        $this->events = [];
        $values = ['site_name' => 'new', 'site_title' => 'title'];
        settingModel()->saveBatch($values);
        self::assertSame([['data', 'settings'], ['settings', $values, $values]], $this->events);
        $this->events = [];
        settingModel()->saveBatch([]);
        self::assertSame([], $this->events);
    }

    public function testPartialBatchFailureStillInvalidatesPreviouslyWrittenValues(): void
    {
        settingModel()->getAll();
        try {
            settingModel()->saveBatch(['site_name' => 'written', 'site_title' => 'rejected']);
            self::fail('Expected database constraint failure');
        } catch (\PDOException) {
            self::assertSame('written', settingModel()->get('site_name'));
            self::assertCount(2, $this->events);
            self::assertSame(['site_name' => 'written'], $this->events[1][1]);
        }
    }

    public function testRuntimeStampsNotifyObserversWithoutRequestingPageInvalidation(): void
    {
        $payload = null;
        add_action('data_changed', function (string $table, int $id, array $settings) use (&$payload): void {
            $payload = $settings;
        });
        settingModel()->set('sched_sweep_at', '123');
        self::assertSame(['sched_sweep_at' => '123'], $payload);
        self::assertCount(2, $this->events);
        self::assertFalse(\SettingModel::affectsPageCache($payload));
        self::assertFalse(\SettingModel::affectsPageCache(['cron_demo_reset_last' => '123', 'cron_demo_reset_status' => 'ok']));
        self::assertTrue(\SettingModel::affectsPageCache(['site_name' => 'new', 'sched_sweep_at' => '123']));
        self::assertTrue(\SettingModel::affectsPageCache(['unknown_key' => 'value']));
        self::assertTrue(\SettingModel::affectsPageCache([]));
    }
}
