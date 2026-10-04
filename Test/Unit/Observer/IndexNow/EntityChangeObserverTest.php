<?php
declare(strict_types=1);

namespace Panth\IndexNow\Test\Unit\Observer\IndexNow;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\UrlFactory;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\IndexNow\Model\IndexNow\Queue;
use Panth\IndexNow\Model\IndexNow\Submitter;
use Panth\IndexNow\Observer\IndexNow\EntityChangeObserver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EntityChangeObserverTest extends TestCase
{
    /** @var array<int, array{0:int,1:string,2:string}> */
    private array $added = [];

    private array $errors = [];

    private array $warnings = [];

    /** Store id => [active, enabled, submitDeletions] */
    private array $stores = [];

    private string $homePage = 'home';

    protected function setUp(): void
    {
        $this->setStatic('pendingIds', []);
        $this->setStatic('shutdownRegistered', true);
    }

    protected function tearDown(): void
    {
        $this->setStatic('pendingIds', []);
        $this->setStatic('queueRef', null);
        $this->setStatic('loggerRef', null);
        $this->setStatic('shutdownRegistered', false);
    }

    private function setStatic(string $name, $value): void
    {
        $property = new \ReflectionProperty(EntityChangeObserver::class, $name);
        $property->setValue(null, $value);
    }

    private function getStatic(string $name)
    {
        return (new \ReflectionProperty(EntityChangeObserver::class, $name))->getValue();
    }

    private function queue(bool $cronMode = false, int $queueId = 10): Queue
    {
        $queue = $this->createStub(Queue::class);
        $queue->method('isCronMode')->willReturn($cronMode);
        $queue->method('add')->willReturnCallback(function ($storeId, $url, $action) use ($queueId) {
            $this->added[] = [$storeId, $url, $action];
            return $queueId + count($this->added) - 1;
        });
        return $queue;
    }

    private function observer(?Queue $queue = null, ?CategoryFactory $categoryFactory = null): EntityChangeObserver
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(function ($path, $scope, $storeId) {
            $flags = $this->stores[$storeId] ?? [false, false, false];
            if ($path === 'panth_index_now/indexnow/enabled') {
                return $flags[1];
            }
            return $path === 'panth_index_now/indexnow/submit_deletions' ? $flags[2] : false;
        });
        $scopeConfig->method('getValue')->willReturnCallback(
            fn ($path) => $path === 'web/default/cms_home_page' ? $this->homePage : null
        );

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function ($storeId) {
            if (!isset($this->stores[$storeId])) {
                throw new \RuntimeException('Unknown store ' . $storeId);
            }
            $store = $this->createStub(Store::class);
            $store->method('getIsActive')->willReturn($this->stores[$storeId][0] ? '1' : '0');
            $store->method('isFrontUrlSecure')->willReturn(true);
            $store->method('getBaseUrl')->willReturn('https://s' . $storeId . '.example/');
            return $store;
        });
        $storeManager->method('getStores')->willReturnCallback(
            fn () => array_fill_keys(array_keys($this->stores), true)
        );

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message) {
            $this->errors[] = $message;
        });
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->warnings[] = $message;
        });

        $url = $this->createStub(UrlInterface::class);
        $url->method('setScope')->willReturnSelf();
        $urlFactory = $this->createStub(UrlFactory::class);
        $urlFactory->method('create')->willReturn($url);

        return new EntityChangeObserver(
            $scopeConfig,
            $storeManager,
            $logger,
            $categoryFactory ?? $this->createStub(CategoryFactory::class),
            $urlFactory,
            $queue ?? $this->queue()
        );
    }

    private function event(string $name, array $data): Observer
    {
        return new Observer(['event' => new Event(['name' => $name] + $data)]);
    }

    private function product(
        int $id,
        int $storeId,
        array $storeIds,
        string $url,
        int $visibility = Visibility::VISIBILITY_BOTH
    ): Product {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getVisibility')->willReturn($visibility);
        $product->method('getStoreId')->willReturn($storeId);
        $product->method('getStoreIds')->willReturn($storeIds);
        $product->method('getProductUrl')->willReturn($url);
        return $product;
    }

    public function testProductSavedInStoreScopeQueuesThatStoreOnly(): void
    {
        $this->stores = [1 => [true, true, false], 2 => [true, true, false]];

        $this->observer()->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 2, [1, 2], 'https://s2.example/p.html'),
        ]));

        $this->assertSame([[2, 'https://s2.example/p.html', Queue::ACTION_UPDATE]], $this->added);
        $this->assertSame([10 => true], $this->getStatic('pendingIds'));
    }

    public function testProductSavedInDefaultScopeQueuesEveryEligibleWebsiteStore(): void
    {
        $this->stores = [
            1 => [true, true, false],
            2 => [false, true, false],
            3 => [true, false, false],
            4 => [true, true, false],
        ];

        $this->observer()->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 0, [0, 1, 2, 3, 4, 4, 9], 'https://x.example/p.html?___store=de&a=1&SID=z'),
        ]));

        $this->assertSame([1, 4], array_column($this->added, 0));
        $this->assertSame('https://x.example/p.html?a=1', $this->added[0][1]);
    }

    public function testStoreQueryParamsAreRemovedCompletely(): void
    {
        $this->stores = [1 => [true, true, false]];

        $this->observer()->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 1, [1], 'https://s1.example/p.html?___store=en&___from_store=de'),
        ]));

        $this->assertSame('https://s1.example/p.html', $this->added[0][1]);
    }

    public function testOtherQueryParamNamesAreKeptVerbatim(): void
    {
        $this->stores = [1 => [true, true, false]];

        $this->observer()->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 1, [1], 'https://s1.example/p.html?utm.source=a&___store=en&x%20y=1'),
        ]));

        $this->assertSame('https://s1.example/p.html?utm.source=a&x%20y=1', $this->added[0][1]);
    }

    public function testInvisibleOrUnsavedProductsAreIgnored(): void
    {
        $this->stores = [1 => [true, true, false]];
        $observer = $this->observer();

        $observer->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 1, [1], 'https://s1.example/p.html', Visibility::VISIBILITY_NOT_VISIBLE),
        ]));
        $observer->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(0, 1, [1], 'https://s1.example/p.html'),
        ]));

        $this->assertSame([], $this->added);
    }

    public function testDeletionIsQueuedOnlyForStoresThatSubmitDeletions(): void
    {
        $this->stores = [1 => [true, true, true], 2 => [true, true, false]];

        $this->observer()->execute($this->event('model_delete_before', [
            'object' => $this->product(5, 2, [1, 2], 'https://s1.example/p.html'),
        ]));

        $this->assertSame([[1, 'https://s1.example/p.html', Queue::ACTION_DELETE]], $this->added);
    }

    public function testEmptyProductUrlIsNotQueued(): void
    {
        $this->stores = [1 => [true, true, false]];

        $this->observer()->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 1, [1], ''),
        ]));

        $this->assertSame([], $this->added);
    }

    public function testUrlResolutionErrorIsSwallowed(): void
    {
        $this->stores = [1 => [true, true, false]];
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn(5);
        $product->method('getVisibility')->willReturn(Visibility::VISIBILITY_BOTH);
        $product->method('getStoreId')->willReturn(1);
        $product->method('getProductUrl')->willThrowException(new \RuntimeException('rewrite missing'));

        $this->observer()->execute($this->event('catalog_product_save_after', ['product' => $product]));

        $this->assertSame([], $this->added);
        $this->assertSame([], $this->errors);
    }

    public function testCronModeQueuesWithoutScheduling(): void
    {
        $this->stores = [1 => [true, true, false]];

        $this->observer($this->queue(true))->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 1, [1], 'https://s1.example/p.html'),
        ]));

        $this->assertCount(1, $this->added);
        $this->assertSame([], $this->getStatic('pendingIds'));
    }

    public function testFailedInsertIsNotScheduled(): void
    {
        $this->stores = [1 => [true, true, false]];

        $this->observer($this->queue(false, 0))->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 1, [1], 'https://s1.example/p.html'),
        ]));

        $this->assertCount(1, $this->added);
        $this->assertSame([], $this->getStatic('pendingIds'));
    }

    public function testShutdownFlushIsRegisteredOnce(): void
    {
        $this->stores = [1 => [true, true, false]];
        $this->setStatic('shutdownRegistered', false);
        $observer = $this->observer();

        $observer->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 1, [1], 'https://s1.example/a.html'),
        ]));
        $observer->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(6, 1, [1], 'https://s1.example/b.html'),
        ]));

        $this->assertTrue($this->getStatic('shutdownRegistered'));
        $this->assertSame([10 => true, 11 => true], $this->getStatic('pendingIds'));
    }

    public function testCategoryUrlIsBuiltForTheTargetStore(): void
    {
        $this->stores = [1 => [true, true, false]];
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn(7);
        $category->method('getLevel')->willReturn(2);
        $category->method('getStoreId')->willReturn(1);
        $category->method('getData')->willReturn(['entity_id' => 7, 'url' => 'stale']);

        $copyData = [];
        $copy = $this->createStub(Category::class);
        $copy->method('setData')->willReturnCallback(function ($data) use (&$copyData, $copy) {
            $copyData = $data;
            return $copy;
        });
        $copy->method('getUrl')->willReturn('https://s1.example/gear.html');
        $factory = $this->createMock(CategoryFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($copy);

        $this->observer(null, $factory)->execute($this->event('catalog_category_save_after', [
            'category' => $category,
        ]));

        $this->assertSame([[1, 'https://s1.example/gear.html', Queue::ACTION_UPDATE]], $this->added);
        $this->assertSame(['entity_id' => 7, 'url' => 'stale'], $copyData);
    }

    public function testRootCategoriesAreIgnored(): void
    {
        $this->stores = [1 => [true, true, false]];
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn(2);
        $category->method('getLevel')->willReturn(1);
        $category->method('getStoreId')->willReturn(1);

        $this->observer()->execute($this->event('catalog_category_save_after', ['category' => $category]));

        $this->assertSame([], $this->added);
    }

    public function testCategoryUrlFailureQueuesNothing(): void
    {
        $this->stores = [1 => [true, true, false]];
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn(7);
        $category->method('getLevel')->willReturn(3);
        $category->method('getStoreId')->willReturn(0);
        $category->method('getStoreIds')->willReturn([0, 1]);
        $factory = $this->createStub(CategoryFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('boom'));

        $this->observer(null, $factory)->execute($this->event('catalog_category_save_after', [
            'category' => $category,
        ]));

        $this->assertSame([], $this->added);
    }

    private function page(int $id, array $stores, string $identifier): Page
    {
        $page = $this->createStub(Page::class);
        $page->method('getId')->willReturn($id);
        $page->method('getStores')->willReturn($stores);
        $page->method('getIdentifier')->willReturn($identifier);
        return $page;
    }

    public function testCmsPageUrlsUseTheStoreBaseUrl(): void
    {
        $this->stores = [1 => [true, true, false], 2 => [true, true, false]];

        $this->observer()->execute($this->event('cms_page_save_after', [
            'object' => $this->page(3, ['2'], '/about-us/'),
        ]));

        $this->assertSame([[2, 'https://s2.example/about-us', Queue::ACTION_UPDATE]], $this->added);
    }

    public function testCmsPageForAllStoreViewsUsesEveryStore(): void
    {
        $this->stores = [1 => [true, true, false], 2 => [true, true, false]];

        $this->observer()->execute($this->event('cms_page_save_after', [
            'object' => $this->page(3, [0], 'faq'),
        ]));

        $this->assertSame([1, 2], array_column($this->added, 0));
    }

    public function testHomePageMapsToTheBaseUrl(): void
    {
        $this->stores = [1 => [true, true, false]];
        $this->homePage = 'home|4';

        $this->observer()->execute($this->event('cms_page_save_after', [
            'object' => $this->page(4, [1], 'home'),
        ]));

        $this->assertSame('https://s1.example/', $this->added[0][1]);
    }

    public function testCmsPageWithoutIdentifierOrIdIsIgnored(): void
    {
        $this->stores = [1 => [true, true, false]];
        $observer = $this->observer();

        $observer->execute($this->event('cms_page_save_after', ['object' => $this->page(4, [1], '/')]));
        $observer->execute($this->event('cms_page_save_after', ['object' => $this->page(0, [1], 'x')]));

        $this->assertSame([], $this->added);
    }

    public function testUnrelatedEventObjectsAreIgnored(): void
    {
        $this->stores = [1 => [true, true, true]];

        $this->observer()->execute($this->event('model_delete_before', ['object' => new \stdClass()]));

        $this->assertSame([], $this->added);
        $this->assertSame([], $this->errors);
    }

    public function testUnexpectedErrorsAreLoggedNotThrown(): void
    {
        $this->stores = [1 => [true, true, false]];
        $queue = $this->createStub(Queue::class);
        $queue->method('add')->willThrowException(new \RuntimeException('db down'));

        $this->observer($queue)->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 1, [1], 'https://s1.example/p.html'),
        ]));

        $this->assertSame(['Panth IndexNow: cannot collect URL.'], $this->errors);
    }

    public function testFlushPendingUrlsSendsCollectedIdsWithTheShortTimeout(): void
    {
        $this->stores = [1 => [true, true, false]];
        $queue = $this->createMock(Queue::class);
        $queue->method('isCronMode')->willReturn(false);
        $queue->method('add')->willReturnOnConsecutiveCalls(21, 22);
        $queue->expects($this->once())->method('flush')
            ->with([21, 22], Submitter::REQUEST_TIMEOUT)
            ->willReturn(2);
        $observer = $this->observer($queue);
        $observer->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 1, [1], 'https://s1.example/a.html'),
        ]));
        $observer->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(6, 1, [1], 'https://s1.example/b.html'),
        ]));

        EntityChangeObserver::flushPendingUrls();
        EntityChangeObserver::flushPendingUrls();

        $this->assertSame([], $this->getStatic('pendingIds'));
    }

    public function testFlushFailureIsLogged(): void
    {
        $queue = $this->createStub(Queue::class);
        $queue->method('flush')->willThrowException(new \RuntimeException('timeout'));
        $this->observer($queue);
        $this->setStatic('pendingIds', [5 => true]);

        EntityChangeObserver::flushPendingUrls();

        $this->assertSame(['Panth IndexNow flush failed.'], $this->errors);
        $this->assertSame([], $this->getStatic('pendingIds'));
    }

    public function testFlushWithoutObserverInstanceDoesNothing(): void
    {
        $this->setStatic('queueRef', null);
        $this->setStatic('pendingIds', [5 => true]);

        EntityChangeObserver::flushPendingUrls();

        $this->assertSame([5 => true], $this->getStatic('pendingIds'));
    }

    public function testCategoryDeletionUsesEveryStoreTheCategoryBelongsTo(): void
    {
        $this->stores = [1 => [true, true, true], 2 => [true, true, true]];
        $category = $this->createStub(Category::class);
        $category->method('getId')->willReturn(7);
        $category->method('getLevel')->willReturn(2);
        $category->method('getStoreId')->willReturn(1);
        $category->method('getStoreIds')->willReturn([0, 1, 2]);
        $category->method('getData')->willReturn(['entity_id' => 7]);
        $copy = $this->createStub(Category::class);
        $copy->method('setData')->willReturnSelf();
        $copy->method('getUrl')->willReturn('https://s.example/gear.html?___store=x');
        $factory = $this->createStub(CategoryFactory::class);
        $factory->method('create')->willReturn($copy);

        $this->observer(null, $factory)->execute($this->event('model_delete_before', ['object' => $category]));

        $this->assertSame([
            [1, 'https://s.example/gear.html', Queue::ACTION_DELETE],
            [2, 'https://s.example/gear.html', Queue::ACTION_DELETE],
        ], $this->added);
    }

    public function testCmsPageDeletionRespectsTheSubmitDeletionsFlag(): void
    {
        $this->stores = [1 => [true, true, false], 2 => [true, true, true]];

        $this->observer()->execute($this->event('model_delete_before', [
            'object' => $this->page(9, [1, 2], 'old-page'),
        ]));

        $this->assertSame([[2, 'https://s2.example/old-page', Queue::ACTION_DELETE]], $this->added);
    }

    public function testCmsPageIsNotQueuedForInactiveOrDisabledStores(): void
    {
        $this->stores = [1 => [false, true, false], 2 => [true, false, false], 3 => [true, true, false]];

        $this->observer()->execute($this->event('cms_page_save_after', [
            'object' => $this->page(9, [], 'faq'),
        ]));

        $this->assertSame([[3, 'https://s3.example/faq', Queue::ACTION_UPDATE]], $this->added);
    }

    public function testNothingIsQueuedWhenIndexNowIsDisabledEverywhere(): void
    {
        $this->stores = [1 => [true, false, true], 2 => [true, false, true]];
        $observer = $this->observer();

        $observer->execute($this->event('cms_page_save_after', ['object' => $this->page(9, [0], 'faq')]));
        $observer->execute($this->event('catalog_product_save_after', [
            'product' => $this->product(5, 0, [1, 2], 'https://s1.example/p.html'),
        ]));

        $this->assertSame([], $this->added);
        $this->assertSame([], $this->getStatic('pendingIds'));
    }

    public function testCmsUrlResolutionFailureIsLoggedAsWarning(): void
    {
        $this->stores = [1 => [true, true, false]];
        $page = $this->createStub(Page::class);
        $page->method('getId')->willReturn(9);
        $page->method('getStores')->willReturn([1]);
        $page->method('getIdentifier')->willThrowException(new \RuntimeException('broken'));

        $this->observer()->execute($this->event('cms_page_save_after', ['object' => $page]));

        $this->assertSame([], $this->added);
        $this->assertSame(['Panth IndexNow: CMS URL resolve failed.'], $this->warnings);
        $this->assertSame([], $this->errors);
    }
}
