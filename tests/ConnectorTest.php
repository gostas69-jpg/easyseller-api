<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk\Tests;

use EasySeller\SiteSdk\Client;
use EasySeller\SiteSdk\Connector;
use EasySeller\SiteSdk\Store\PdoStorage;
use PHPUnit\Framework\TestCase;

final class ConnectorTest extends TestCase
{
    private FakeEasySeller $es;
    private FakeSite $site;
    private PdoStorage $storage;
    private Connector $connector;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->es = new FakeEasySeller();
        $this->site = new FakeSite();
        $this->storage = new PdoStorage(new \PDO('sqlite::memory:'), 'es_');
        $this->storage->install();
        $this->connector = new Connector(new Client('es_site_test', 'https://easyseller.test/api/site/v2', $this->es), $this->storage, $this->site, $this->site,
            function (string $t): void {
                $this->log[] = $t;
            });
        // сверку и сведения о сайте в этих тестах не ждём
        $this->storage->setState('reconcile_at', (string)time());
        $this->storage->setState('site_at', (string)time());
    }

    public function testProductsGoInBatchesOf200(): void
    {
        for ($i = 1; $i <= 450; $i++) {
            $this->site->products[(string)$i] = ['id' => (string)$i, 'version' => 1, 'name' => 'Стол ' . $i];
            $this->connector->queue(Connector::PRODUCT, $i);
        }
        $this->connector->run();
        $calls = $this->es->calls('PUT', '/products');
        self::assertCount(3, $calls);
        self::assertSame([200, 200, 50], array_map(fn($c) => count($c['body']['items']), $calls));
        self::assertFalse($this->storage->pending(Connector::PRODUCT));
    }

    public function testBigProductsSplitByBytes(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->site->products[(string)$i] = ['id' => (string)$i, 'version' => 1, 'description_html' => str_repeat('x', 300000)];
            $this->connector->queue(Connector::PRODUCT, $i);
        }
        $this->connector->run();
        foreach ($this->es->calls('PUT', '/products') as $c) {
            self::assertLessThan(1000000, strlen((string)$c['raw']));
        }
        self::assertGreaterThan(1, count($this->es->calls('PUT', '/products')));
    }

    public function testThroughStopsBelowUnsentOrder(): void
    {
        $this->site->orders = ['5' => ['id' => '5', 'version' => 1], '7' => ['id' => '7', 'version' => 1]];
        $this->connector->queue(Connector::ORDER, 5);
        $this->connector->queue(Connector::ORDER, 7);
        $this->connector->run();
        self::assertSame('7', $this->es->calls('PUT', '/orders')[0]['body']['through']);

        // заказ 5 не ушёл (EasySeller недоступен): отметка 4
        $this->es->override['PUT /orders'] = ['status' => 503, 'body' => ['ok' => false, 'error' => ['code' => 'internal', 'message' => 'нет']]];
        $this->connector->queue(Connector::ORDER, 5);
        $this->connector->run();
        unset($this->es->override['PUT /orders']);
        self::assertSame('5', $this->storage->minPendingOrder());
    }

    public function testRejectedRowIsClosedAndLogged(): void
    {
        $this->site->products['1'] = ['id' => '1', 'version' => 1];
        $this->es->override['PUT /products'] = ['status' => 200, 'body' => ['ok' => true, 'items' => [['id' => '1', 'status' => 'rejected', 'message' => 'Нет вариантов']]]];
        $this->connector->queue(Connector::PRODUCT, 1);
        $this->connector->run();
        self::assertFalse($this->storage->pending(Connector::PRODUCT));
        self::assertStringContainsString('Нет вариантов', implode("\n", $this->log));
    }

    public function testTemporaryErrorIsRetriedLater(): void
    {
        $this->site->products['1'] = ['id' => '1', 'version' => 1];
        $this->es->override['PUT /products'] = ['status' => 429, 'headers' => ['retry-after' => '120'], 'body' => ['ok' => false, 'error' => ['code' => 'rate_limit', 'message' => 'много']]];
        $this->connector->queue(Connector::PRODUCT, 1);
        $this->connector->run();
        self::assertTrue($this->storage->pending(Connector::PRODUCT));
        self::assertSame([], $this->storage->due(10), 'повтор не раньше, чем через 120 секунд');
    }

    public function testPullAppliesOnceAndReportsResults(): void
    {
        $this->es->changes = [['id' => 11, 'type' => 'price.set', 'data' => ['variant_id' => '318', 'price' => '44990.00']]];
        self::assertSame(1, $this->connector->pull());
        self::assertSame('44990.00', $this->site->prices['318']);
        $results = $this->es->calls('POST', '/changes/results');
        self::assertSame([['id' => 11, 'status' => 'applied']], $results[0]['body']['items']);

        // EasySeller выдал то же изменение ещё раз (результат не дошёл): не применяем второй раз
        $this->storage->setState('changes_after', '0');
        $this->connector->pull();
        self::assertSame(1, $this->site->applied);
    }

    public function testUnsupportedChangeIsRejected(): void
    {
        $this->es->changes = [['id' => 12, 'type' => 'stock.set', 'data' => []]];
        $this->connector->pull();
        $item = $this->es->calls('POST', '/changes/results')[0]['body']['items'][0];
        self::assertSame('rejected', $item['status']);
        self::assertStringContainsString('не умеет', $item['message']);
    }

    public function testWebhookFromEasySellerIsApplied(): void
    {
        $body = json_encode(['event' => 'changes', 'items' => [['id' => 21, 'type' => 'price.set', 'data' => ['variant_id' => '1', 'price' => '100.00']]]]);
        $r = $this->connector->webhook((string)$body, '176.12.79.195');
        self::assertFalse($r['pull']);
        self::assertSame(['ok' => true, 'items' => [['id' => 21, 'status' => 'applied']]], json_decode($r['body'], true));
        self::assertSame('100.00', $this->site->prices['1']);
    }

    public function testWebhookFromOtherAddressIsNotApplied(): void
    {
        $body = json_encode(['event' => 'changes', 'items' => [['id' => 22, 'type' => 'price.set', 'data' => ['variant_id' => '1', 'price' => '1.00']]]]);
        $r = $this->connector->webhook((string)$body, '203.0.113.5');
        self::assertTrue($r['pull'], 'чужой адрес: сайт сам идёт за изменениями');
        self::assertArrayNotHasKey('1', $this->site->prices);
    }

    public function testResyncAnswersAfterDataIsSent(): void
    {
        $this->site->products = ['1' => ['id' => '1', 'version' => 1], '2' => ['id' => '2', 'version' => 1]];
        $this->es->changes = [['id' => 31, 'type' => 'resync', 'data' => ['what' => 'products']]];
        $this->connector->pull();
        self::assertCount(1, $this->es->calls('PUT', '/products'));
        self::assertSame('applied', $this->es->calls('POST', '/changes/results')[0]['body']['items'][0]['status']);
    }

    public function testDocumentWaitsForUnknownOrder(): void
    {
        $this->es->unknownOrders['14'] = true;
        $this->connector->document('14', 'cdek_barcode', "%PDF-1.4 test");
        $this->connector->run();
        self::assertTrue($this->storage->pending(Connector::DOCUMENT), 'заказа ещё нет в EasySeller: повтор позже');
        $put = $this->es->calls('PUT', '/orders/14/documents/cdek_barcode')[0];
        self::assertSame('application/pdf', $put['type']);
        self::assertSame("%PDF-1.4 test", $put['raw']);
    }

    public function testSiteInfoCarriesProtocolAndLoadsWebhookAddresses(): void
    {
        $this->storage->setState('site_at', '0');
        $this->connector->run();
        $put = $this->es->calls('PUT', '/site')[0]['body'];
        self::assertSame(2, $put['protocol']);
        self::assertSame('test 1.0', $put['module']);
        self::assertSame('176.12.79.195', $this->storage->getState('webhook_ips'));
    }

    public function testQueueIsMergedAndCountsUpdates(): void
    {
        $this->connector->queue(Connector::STOCKS);
        $this->connector->queue(Connector::STOCKS);
        $rows = $this->storage->due(10);
        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]['version']);
    }
}
