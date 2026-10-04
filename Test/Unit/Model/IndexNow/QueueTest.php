<?php
declare(strict_types=1);

namespace Panth\IndexNow\Test\Unit\Model\IndexNow;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\IndexNow\Model\IndexNow\Queue;
use Panth\IndexNow\Model\IndexNow\Submitter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueueTest extends TestCase
{
    private array $wheres = [];

    private array $warnings = [];

    private array $errors = [];

    private function select(): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->wheres[] = [$cond, $value];
            return $select;
        });
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        return $select;
    }

    private function resource(AdapterInterface $connection): ResourceConnection
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->warnings[] = $message;
        });
        $logger->method('error')->willReturnCallback(function ($message) {
            $this->errors[] = $message;
        });
        return $logger;
    }

    private function queue(
        ?ResourceConnection $resource = null,
        ?Submitter $submitter = null,
        ?ScopeConfigInterface $scopeConfig = null
    ): Queue {
        return new Queue(
            $resource ?? $this->createStub(ResourceConnection::class),
            $submitter ?? $this->createStub(Submitter::class),
            $scopeConfig ?? $this->createStub(ScopeConfigInterface::class),
            $this->logger()
        );
    }

    public function testFlushDeletesSentRowsAndCountsFailures(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->select());
        $connection->method('fetchAll')->willReturn([
            ['queue_id' => '1', 'store_id' => '1', 'url' => 'https://a.example/x.html'],
            ['queue_id' => '2', 'store_id' => '1', 'url' => 'https://a.example/y.html'],
            ['queue_id' => '3', 'store_id' => '2', 'url' => 'https://b.example/x.html'],
        ]);
        $deleted = [];
        $connection->method('delete')->willReturnCallback(
            static function ($table, $where) use (&$deleted): int {
                $deleted[] = $where;
                return 0;
            }
        );
        $connection->expects($this->once())->method('update')->with(
            'panth_index_now_queue',
            $this->callback(static fn (array $bind): bool => (string) $bind['attempts'] === 'attempts + 1'),
            ['queue_id IN (?)' => [3]]
        );

        $submitter = $this->createStub(Submitter::class);
        $submitter->method('submit')->willReturnCallback(
            static fn (array $urls, int $storeId, int $timeout): bool => $storeId === 1 && $timeout === 4
        );

        $queue = $this->queue($this->resource($connection), $submitter);

        $this->assertSame(2, $queue->flush([1, 2, 3], Submitter::REQUEST_TIMEOUT));
        $this->assertSame(['queue_id IN (?)' => [1, 2]], $deleted[0]);
        $this->assertSame(['attempts >= ?' => Queue::MAX_ATTEMPTS], $deleted[1]);
        $this->assertContains(['queue_id IN (?)', [1, 2, 3]], $this->wheres);
        $this->assertContains(['attempts < ?', Queue::MAX_ATTEMPTS], $this->wheres);
        $this->assertSame([], $this->warnings);
    }

    public function testFlushWithEmptyIdListDoesNothing(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        $this->assertSame(0, $this->queue($resource)->flush([]));
        $this->assertSame(0, $this->queue($resource)->flush([0, '0', 'abc']));
    }

    public function testFlushAllAppliesTheMinimumAgeAndNoIdFilter(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->select());
        $connection->method('fetchAll')->willReturn([]);
        $connection->method('delete')->willReturn(0);

        $this->assertSame(0, $this->queue($this->resource($connection))->flush(null, Submitter::TIMEOUT, 120));
        $this->assertContains(['updated_at <= DATE_SUB(NOW(), INTERVAL ? SECOND)', 120], $this->wheres);
        $this->assertNotContains('queue_id IN (?)', array_column($this->wheres, 0));
    }

    public function testDuplicateUrlsAreSubmittedOnceButAllRowsAreCleared(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->select());
        $connection->method('fetchAll')->willReturn([
            ['queue_id' => '4', 'store_id' => '1', 'url' => 'https://a.example/x.html'],
            ['queue_id' => '5', 'store_id' => '1', 'url' => 'https://a.example/x.html'],
        ]);
        $deleted = [];
        $connection->method('delete')->willReturnCallback(
            static function ($table, $where) use (&$deleted): int {
                $deleted[] = $where;
                return 0;
            }
        );
        $submitted = [];
        $submitter = $this->createStub(Submitter::class);
        $submitter->method('submit')->willReturnCallback(
            static function (array $urls) use (&$submitted): bool {
                $submitted = $urls;
                return true;
            }
        );

        $this->assertSame(2, $this->queue($this->resource($connection), $submitter)->flush());
        $this->assertSame(['https://a.example/x.html'], $submitted);
        $this->assertSame(['queue_id IN (?)' => [4, 5]], $deleted[0]);
    }

    public function testSubmitterExceptionCountsAsFailedAttemptAndDropsExhaustedRows(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->select());
        $connection->method('fetchAll')->willReturn([
            ['queue_id' => '9', 'store_id' => '3', 'url' => 'https://c.example/x.html'],
        ]);
        $connection->expects($this->once())->method('update')
            ->with('panth_index_now_queue', $this->anything(), ['queue_id IN (?)' => [9]]);
        $connection->expects($this->once())->method('delete')
            ->with('panth_index_now_queue', ['attempts >= ?' => Queue::MAX_ATTEMPTS])
            ->willReturn(2);
        $submitter = $this->createStub(Submitter::class);
        $submitter->method('submit')->willThrowException(new \RuntimeException('network'));

        $this->assertSame(0, $this->queue($this->resource($connection), $submitter)->flush());
        $this->assertSame(['Panth IndexNow: queue flush failed.'], $this->errors);
        $this->assertSame(['Panth IndexNow: dropped 2 queued URL(s) after 5 failed attempts.'], $this->warnings);
    }

    public function testAddUpsertsByUrlHashAndReturnsTheQueueId(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($this->select());
        $connection->expects($this->once())->method('insertOnDuplicate')->with(
            'panth_index_now_queue',
            [
                'store_id' => 2,
                'url_hash' => sha1('https://a.example/x.html'),
                'url'      => 'https://a.example/x.html',
                'action'   => Queue::ACTION_DELETE,
                'attempts' => 0,
            ],
            ['url', 'action', 'attempts']
        );
        $connection->method('fetchOne')->willReturn('17');

        $id = $this->queue($this->resource($connection))->add(2, 'https://a.example/x.html', Queue::ACTION_DELETE);

        $this->assertSame(17, $id);
        $this->assertContains(['store_id = ?', 2], $this->wheres);
        $this->assertContains(['url_hash = ?', sha1('https://a.example/x.html')], $this->wheres);
    }

    public function testAddNormalisesUnknownActionsToUpdate(): void
    {
        $action = null;
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($this->select());
        $connection->method('insertOnDuplicate')->willReturnCallback(
            static function ($table, array $data) use (&$action): int {
                $action = $data['action'];
                return 1;
            }
        );
        $connection->method('fetchOne')->willReturn(false);

        $this->assertSame(0, $this->queue($this->resource($connection))->add(1, 'https://a.example/', 'purge'));
        $this->assertSame(Queue::ACTION_UPDATE, $action);
    }

    public function testAddRejectsInvalidInput(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');
        $queue = $this->queue($resource);

        $this->assertSame(0, $queue->add(0, 'https://a.example/'));
        $this->assertSame(0, $queue->add(-1, 'https://a.example/'));
        $this->assertSame(0, $queue->add(1, ''));
    }

    public function testCronModeFlag(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn ($path) => $path === Queue::XML_SUBMISSION_MODE ? 'cron' : null
        );
        $this->assertTrue($this->queue(null, null, $scopeConfig)->isCronMode());

        $requestMode = $this->createStub(ScopeConfigInterface::class);
        $requestMode->method('getValue')->willReturn(null);
        $this->assertFalse($this->queue(null, null, $requestMode)->isCronMode());
    }
}
