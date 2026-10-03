<?php
declare(strict_types=1);

namespace Panth\IndexNow\Model\IndexNow;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class Queue
{
    public const TABLE = 'panth_index_now_queue';

    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    public const MODE_REQUEST = 'request';
    public const MODE_CRON = 'cron';

    public const MAX_ATTEMPTS = 5;

    public const XML_SUBMISSION_MODE = 'panth_index_now/indexnow/submission_mode';

    private const FLUSH_LIMIT = 10000;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Submitter $submitter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isCronMode(): bool
    {
        return (string) $this->scopeConfig->getValue(self::XML_SUBMISSION_MODE) === self::MODE_CRON;
    }

    public function add(int $storeId, string $url, string $action = self::ACTION_UPDATE): int
    {
        if ($storeId <= 0 || $url === '') {
            return 0;
        }
        $action = $action === self::ACTION_DELETE ? self::ACTION_DELETE : self::ACTION_UPDATE;
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $hash = sha1($url);

        $connection->insertOnDuplicate(
            $table,
            [
                'store_id' => $storeId,
                'url_hash' => $hash,
                'url'      => $url,
                'action'   => $action,
                'attempts' => 0,
            ],
            ['url', 'action', 'attempts']
        );

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($table, ['queue_id'])
                ->where('store_id = ?', $storeId)
                ->where('url_hash = ?', $hash)
                ->limit(1)
        );
    }

    public function flush(?array $ids = null, int $timeout = Submitter::TIMEOUT, int $minAgeSeconds = 0): int
    {
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if ($ids === []) {
                return 0;
            }
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        $select = $connection->select()
            ->from($table, ['queue_id', 'store_id', 'url'])
            ->where('attempts < ?', self::MAX_ATTEMPTS)
            ->order('queue_id ASC')
            ->limit(self::FLUSH_LIMIT);
        if ($ids !== null) {
            $select->where('queue_id IN (?)', $ids);
        }
        if ($minAgeSeconds > 0) {
            $select->where('updated_at <= DATE_SUB(NOW(), INTERVAL ? SECOND)', $minAgeSeconds);
        }

        $byStore = [];
        foreach ($connection->fetchAll($select) as $row) {
            $byStore[(int) $row['store_id']][(int) $row['queue_id']] = (string) $row['url'];
        }

        $sent = 0;
        foreach ($byStore as $storeId => $urls) {
            $queueIds = array_keys($urls);
            $ok = false;
            try {
                $ok = $this->submitter->submit(array_values(array_unique($urls)), $storeId, $timeout);
            } catch (\Throwable $e) {
                $this->logger->error('Panth IndexNow: queue flush failed.', ['error' => $e->getMessage()]);
            }
            if ($ok) {
                $connection->delete($table, ['queue_id IN (?)' => $queueIds]);
                $sent += count($queueIds);
                continue;
            }
            $connection->update(
                $table,
                ['attempts' => new \Zend_Db_Expr('attempts + 1')],
                ['queue_id IN (?)' => $queueIds]
            );
        }

        $dropped = $connection->delete($table, ['attempts >= ?' => self::MAX_ATTEMPTS]);
        if ($dropped > 0) {
            $this->logger->warning(
                sprintf('Panth IndexNow: dropped %d queued URL(s) after %d failed attempts.', $dropped, self::MAX_ATTEMPTS)
            );
        }

        return $sent;
    }
}
