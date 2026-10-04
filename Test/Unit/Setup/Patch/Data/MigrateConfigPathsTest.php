<?php
declare(strict_types=1);

namespace Panth\IndexNow\Test\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Panth\IndexNow\Setup\Patch\Data\MigrateConfigPaths;
use PHPUnit\Framework\TestCase;

class MigrateConfigPathsTest extends TestCase
{
    private array $wheres = [];

    private array $updates = [];

    private function patch(array $rows, array $existingNewPaths): MigrateConfigPaths
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[] = [$cond, $value];
            return $select;
        });

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);
        $connection->method('fetchOne')->willReturnCallback(function () use ($existingNewPaths) {
            $last = end($this->wheres);
            return in_array($last[1], $existingNewPaths, true) ? '99' : false;
        });
        $connection->method('update')->willReturnCallback(function ($table, $bind, $where) {
            $this->updates[] = [$table, $bind, $where];
            return 1;
        });

        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturn('core_config_data');

        return new MigrateConfigPaths($setup);
    }

    public function testLegacyRowsAreRenamedToTheNewSection(): void
    {
        $patch = $this->patch([
            ['config_id' => '5', 'scope' => 'default', 'scope_id' => '0', 'path' => 'panth_seo/indexnow/enabled'],
            ['config_id' => '6', 'scope' => 'stores', 'scope_id' => '2', 'path' => 'panth_seo/indexnow/api_key'],
        ], []);

        $this->assertSame($patch, $patch->apply());
        $this->assertSame([
            ['core_config_data', ['path' => 'panth_index_now/indexnow/enabled'], ['config_id = ?' => 5]],
            ['core_config_data', ['path' => 'panth_index_now/indexnow/api_key'], ['config_id = ?' => 6]],
        ], $this->updates);
        $this->assertSame(['path LIKE ?', 'panth\_seo/indexnow/%'], $this->wheres[0]);
        $this->assertContains(['scope_id = ?', 2], $this->wheres);
    }

    public function testRowIsKeptWhenTheNewPathAlreadyExistsForThatScope(): void
    {
        $this->patch([
            ['config_id' => '5', 'scope' => 'default', 'scope_id' => '0', 'path' => 'panth_seo/indexnow/enabled'],
            [
                'config_id' => '7',
                'scope'     => 'default',
                'scope_id'  => '0',
                'path'      => 'panth_seo/indexnow/submit_deletions',
            ],
        ], ['panth_index_now/indexnow/enabled'])->apply();

        $this->assertCount(1, $this->updates);
        $this->assertSame(['path' => 'panth_index_now/indexnow/submit_deletions'], $this->updates[0][1]);
        $this->assertSame(['config_id = ?' => 7], $this->updates[0][2]);
    }

    public function testRowsOutsideTheLegacyPrefixAreSkipped(): void
    {
        $this->patch([
            ['config_id' => '8', 'scope' => 'default', 'scope_id' => '0', 'path' => 'panthXseo/indexnow/enabled'],
        ], [])->apply();

        $this->assertSame([], $this->updates);
    }

    public function testNoDependenciesOrAliases(): void
    {
        $this->assertSame([], MigrateConfigPaths::getDependencies());
        $this->assertSame([], $this->patch([], [])->getAliases());
    }
}
