<?php
declare(strict_types=1);

namespace Panth\IndexNow\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class MigrateConfigPaths implements DataPatchInterface
{
    private const LEGACY_PREFIX = 'panth_seo/indexnow/';
    private const NEW_PREFIX    = 'panth_index_now/indexnow/';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['config_id', 'scope', 'scope_id', 'path'])
                ->where('path LIKE ?', str_replace('_', '\_', self::LEGACY_PREFIX) . '%')
        );

        foreach ($rows as $row) {
            $path = (string) $row['path'];
            if (!str_starts_with($path, self::LEGACY_PREFIX)) {
                continue;
            }
            $newPath = self::NEW_PREFIX . substr($path, strlen(self::LEGACY_PREFIX));

            $exists = $connection->fetchOne(
                $connection->select()
                    ->from($table, ['config_id'])
                    ->where('scope = ?', $row['scope'])
                    ->where('scope_id = ?', (int) $row['scope_id'])
                    ->where('path = ?', $newPath)
            );
            if ($exists) {
                continue;
            }

            $connection->update(
                $table,
                ['path' => $newPath],
                ['config_id = ?' => (int) $row['config_id']]
            );
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
