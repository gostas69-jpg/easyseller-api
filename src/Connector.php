<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk;

use EasySeller\SiteSdk\Contract\ChangeHandler;
use EasySeller\SiteSdk\Contract\DataSource;
use EasySeller\SiteSdk\Contract\Storage;

/**
 * Обмен сайта с EasySeller по протоколу 2. Сайт:
 * - зовёт `queue()`, когда изменилась модель, заказ, склады, остатки или справочник;
 * - раз в минуту (cron) зовёт `run()`: отправка очереди, забор изменений, сверка раз в час, сведения о сайте раз в сутки;
 * - на адресе уведомлений зовёт `webhook()` и отдаёт его ответ;
 * - печатные формы заказа отдаёт `document()`.
 * Всё, что не ушло, повторяется само: через 1, 5, 15, 60 минут, дальше раз в час, сутки.
 */
final class Connector
{
    public const PRODUCT = 'product';
    public const ORDER = 'order';
    public const WAREHOUSES = 'warehouses';
    public const STOCKS = 'stocks';
    public const PROPERTIES = 'properties';
    public const SITE = 'site';
    public const DOCUMENT = 'document';

    public const PROTOCOL = 2;

    /** Виды, которые уходят целиком (номер строки очереди пустой). */
    private const WHOLE = [self::SITE, self::PROPERTIES, self::WAREHOUSES, self::STOCKS];
    private const BATCH = 200;
    private const MAX_BYTES = 900000;
    private const BACKOFF = [1 => 1, 2 => 5, 3 => 15, 4 => 60];
    private const GIVE_UP = 86400;
    private const CHANGES_LIMIT = 100;
    private const RESULTS_PART = 20;
    private const RECONCILE_EVERY = 3600;
    private const SITE_EVERY = 86400;
    private const IPS_EVERY = 86400;
    private const KEEP_DAYS = 7;
    private const RUNNING = 'running';

    /** @var callable|null */
    private $log;

    public function __construct(
        private readonly Client $client,
        private readonly Storage $storage,
        private readonly DataSource $data,
        private readonly ChangeHandler $handler,
        ?callable $log = null,
    ) {
        $this->log = $log;
    }

    /** Поставить отправку: `product` и `order` с номером, остальные виды целиком. */
    public function queue(string $kind, string|int|null $id = null): void
    {
        if (in_array($kind, self::WHOLE, true)) {
            $this->storage->push($kind, '');
            return;
        }
        if (($kind === self::PRODUCT || $kind === self::ORDER) && (string)$id !== '') {
            $this->storage->push($kind, (string)$id);
            if ($kind === self::ORDER) {
                $this->storage->push(self::STOCKS, ''); // заказ меняет остатки складов
            }
        }
    }

    /** Печатная форма заказа (сейчас `cdek_barcode`, PDF): ляжет в хранилище и уйдёт с очередью. */
    public function document(string $orderId, string $kind, string $bytes, string $contentType = 'application/pdf'): void
    {
        $this->storage->putDocument($orderId, $kind, $bytes, $contentType);
        $this->storage->push(self::DOCUMENT, $orderId);
    }

    /**
     * Проход раз в минуту. Ответ: сколько отправлено строк очереди и обработано изменений.
     *
     * @return array{sent: int, changes: int}
     */
    public function run(int $budget = 40): array
    {
        $started = time();
        try {
            $this->reconcile();
            if ((int)$this->storage->getState('site_at') < time() - self::SITE_EVERY) {
                $this->queue(self::SITE);
            }
        } catch (\Throwable $e) {
            $this->say('сверка: ' . $e->getMessage());
        }
        $sent = $this->send($budget);
        $changes = time() - $started < $budget ? $this->pull() : 0;
        $this->storage->cleanup(self::KEEP_DAYS);
        return ['sent' => $sent, 'changes' => $changes];
    }

    /**
     * Адрес уведомлений. Изменения в теле применяются, только если запрос пришёл с адреса EasySeller (`webhook_ips`
     * из `GET /site`). Ответ отдайте как есть: код и JSON. `pull`: тело не применено, после ответа зовите `pull()`
     * (лучше в фоне), иначе изменения заберёт следующий `run()`.
     *
     * @return array{status: int, body: string, pull: bool}
     */
    public function webhook(string $body, string $remoteIp): array
    {
        $in = json_decode($body, true);
        $items = is_array($in) && is_array($in['items'] ?? null) && array_is_list($in['items']) ? $in['items'] : [];
        if ($items !== [] && $this->trusted($remoteIp)) {
            $results = $this->applyAll($items, true);
            return ['status' => 200, 'body' => self::json(['ok' => true, 'items' => $results]), 'pull' => false];
        }
        return ['status' => 200, 'body' => '{"ok":true}', 'pull' => true];
    }

    /** Забрать ждущие изменения, применить, ответить результатами. Ответ: сколько обработано. */
    public function pull(): int
    {
        if (!$this->storage->lock('changes', 15)) {
            return 0;
        }
        $done = 0;
        try {
            do {
                $after = (int)$this->storage->getState('changes_after');
                $answer = $this->client->get('/changes', ['after' => $after, 'limit' => self::CHANGES_LIMIT]);
                $items = array_values((array)($answer['items'] ?? []));
                if ($items === []) {
                    break;
                }
                $results = [];
                $waiting = false;
                foreach ($items as $row) {
                    $change = is_array($row) ? Change::fromArray($row) : null;
                    if ($change === null) {
                        continue;
                    }
                    $r = $this->applyOne($change);
                    if ($r->status === Result::WAIT) {
                        $waiting = true; // ответ позже, номер дальше не двигаем
                        break;
                    }
                    $results[] = ['id' => $change->id] + $r->toArray();
                }
                foreach (array_chunk($results, self::RESULTS_PART) as $part) {
                    $this->client->post('/changes/results', ['items' => $part]);
                    $this->storage->setState('changes_after', (string)max($after, max(array_column($part, 'id'))));
                    $done += count($part);
                }
            } while (!$waiting && count($items) === self::CHANGES_LIMIT);
        } catch (ApiException $e) {
            $this->say('изменения: ' . $e->getMessage());
        } finally {
            $this->storage->unlock('changes');
        }
        return $done;
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string, mixed>>
     */
    private function applyAll(array $items, bool $locked): array
    {
        if ($locked && !$this->storage->lock('changes', 15)) {
            return [];
        }
        $out = [];
        try {
            foreach (array_slice($items, 0, self::CHANGES_LIMIT) as $row) {
                $change = is_array($row) ? Change::fromArray($row) : null;
                if ($change === null) {
                    continue;
                }
                $r = $this->applyOne($change);
                if ($r->status !== Result::WAIT) {
                    $out[] = ['id' => $change->id] + $r->toArray();
                }
            }
        } finally {
            if ($locked) {
                $this->storage->unlock('changes');
            }
        }
        return $out;
    }

    /** Применить один раз: повтор отдаёт прежний итог; оборванное применение («running») доводится с поправкой. */
    private function applyOne(Change $change): Result
    {
        $prev = $this->storage->applied($change->id);
        if ($prev !== null && ($prev['status'] ?? '') !== self::RUNNING) {
            return Result::fromArray($prev);
        }
        $retry = $prev !== null;
        if ($change->type === 'resync') {
            if (!$retry) {
                $this->storage->saveApplied($change->id, ['status' => self::RUNNING]);
                $this->resync((string)($change->data['what'] ?? ''), isset($change->data['since']) ? (string)$change->data['since'] : null);
            }
            $this->send(30);
            if ($this->storage->pending(self::kindOf((string)($change->data['what'] ?? '')))) {
                return Result::wait();
            }
            $this->storage->saveApplied($change->id, ['status' => Result::APPLIED]);
            return Result::applied();
        }
        $this->storage->saveApplied($change->id, ['status' => self::RUNNING]);
        try {
            $r = $this->handler->handle($change);
        } catch (\Throwable $e) {
            $this->say("изменение #{$change->id} ({$change->type}): " . $e->getMessage());
            $r = Result::rejected('Сайт не смог применить изменение');
        }
        // повтор после обрыва: конфликт версии значит, что прошлая попытка записала
        if ($retry && $r->status === Result::CONFLICT) {
            $r = Result::applied($r->result);
        }
        $this->storage->saveApplied($change->id, $r->toArray());
        return $r;
    }

    /** Просьба EasySeller прислать заново. */
    private function resync(string $what, ?string $since): void
    {
        $at = null;
        if ($since !== null && $since !== '') {
            try {
                $at = (new \DateTimeImmutable($since))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            } catch (\Throwable) {
                $at = null;
            }
        }
        if ($what === 'products' || $what === 'orders') {
            foreach ($this->data->changedIds($what === 'products' ? self::PRODUCT : self::ORDER, $at) as $id) {
                $this->queue($what === 'products' ? self::PRODUCT : self::ORDER, (string)$id);
            }
        } elseif (in_array($what, self::WHOLE, true)) {
            $this->queue($what);
        }
    }

    /** Сверка раз в час: всё, что изменилось за последние два часа, ещё раз в очередь. */
    private function reconcile(): void
    {
        $last = (int)$this->storage->getState('reconcile_at');
        if ($last > time() - self::RECONCILE_EVERY) {
            return;
        }
        $since = (new \DateTimeImmutable())->setTimestamp($last > 0 ? $last - self::RECONCILE_EVERY : time() - 2 * self::RECONCILE_EVERY);
        foreach ([self::PRODUCT, self::ORDER] as $kind) {
            foreach ($this->data->changedIds($kind, $since) as $id) {
                $this->queue($kind, (string)$id);
            }
        }
        $this->queue(self::WAREHOUSES);
        $this->queue(self::STOCKS);
        $this->storage->setState('reconcile_at', (string)time());
    }

    /** Отправить строки очереди, которым пора. Ответ: сколько строк закрыто. */
    private function send(int $budget): int
    {
        if (!$this->storage->lock('outbox', 0)) {
            return 0;
        }
        $started = time();
        $sent = 0;
        try {
            $rows = $this->storage->due(1000);
            foreach ([self::SITE, self::PROPERTIES, self::WAREHOUSES, self::PRODUCT, self::ORDER, self::STOCKS, self::DOCUMENT] as $kind) {
                $mine = array_values(array_filter($rows, fn(array $r) => $r['kind'] === $kind));
                if ($mine === [] || time() - $started > $budget) {
                    continue;
                }
                $sent += match ($kind) {
                    self::PRODUCT, self::ORDER => $this->sendRecords($kind, $mine),
                    self::DOCUMENT => $this->sendDocuments($mine),
                    default => $this->sendWhole($kind, $mine),
                };
            }
        } finally {
            $this->storage->unlock('outbox');
        }
        return $sent;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sendRecords(string $kind, array $rows): int
    {
        $byId = [];
        foreach ($rows as $r) {
            $byId[$r['entity']][] = $r;
        }
        $ids = array_map('strval', array_keys($byId)); // числовые ключи PHP превращает в int
        $items = $kind === self::PRODUCT ? $this->data->products($ids) : $this->data->orders($ids);
        $found = [];
        foreach ($items as $item) {
            $found[(string)($item['id'] ?? '')] = true;
        }
        $n = 0;
        foreach ($byId as $id => $list) {
            if (!isset($found[(string)$id])) {
                // сайт такого номера не знает: закрыть, иначе отметка заказов встала бы на нём
                foreach ($list as $r) {
                    $this->storage->sent($r['id'], $r['version']);
                    $n++;
                }
                unset($byId[$id]);
            }
        }
        foreach ($this->fit($items) as $part) {
            try {
                $body = ['items' => $part];
                if ($kind === self::ORDER) {
                    $body['through'] = $this->through(array_map(fn($i) => (string)($i['id'] ?? ''), $part));
                }
                $answer = $this->client->put($kind === self::PRODUCT ? '/products' : '/orders', $body);
                foreach ((array)($answer['items'] ?? []) as $res) {
                    foreach ($byId[(string)($res['id'] ?? '')] ?? [] as $r) {
                        if (($res['status'] ?? '') === 'rejected') {
                            $this->say("{$kind} #{$r['entity']}: EasySeller не принял: " . (string)($res['message'] ?? ''));
                            $this->storage->close($r['id'], (string)($res['message'] ?? 'отказ EasySeller'));
                        } else {
                            $this->storage->sent($r['id'], $r['version']);
                        }
                        $n++;
                    }
                }
            } catch (ApiException $e) {
                foreach ($part as $item) {
                    foreach ($byId[(string)($item['id'] ?? '')] ?? [] as $r) {
                        $this->failed($r, $e);
                    }
                }
            }
        }
        return $n;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sendWhole(string $kind, array $rows): int
    {
        try {
            if ($kind === self::SITE) {
                $answer = $this->client->put('/site', ['protocol' => self::PROTOCOL] + $this->data->site());
                foreach ((array)($answer['warnings'] ?? []) as $w) {
                    $this->say('EasySeller: ' . $w);
                }
                $this->storage->setState('site_at', (string)time());
                $this->refreshIps(true);
            } else {
                $items = match ($kind) {
                    self::PROPERTIES => $this->data->properties(),
                    self::WAREHOUSES => $this->data->warehouses(),
                    default => $this->data->stocks(),
                };
                // справочник и склады одним запросом: EasySeller заменяет их целиком
                foreach ($kind === self::STOCKS ? array_chunk($items, self::BATCH) : [$items] as $part) {
                    $this->client->put('/' . $kind, ['items' => array_values($part)]);
                }
            }
            foreach ($rows as $r) {
                $this->storage->sent($r['id'], $r['version']);
            }
            return count($rows);
        } catch (ApiException $e) {
            foreach ($rows as $r) {
                $this->failed($r, $e);
            }
            return 0;
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function sendDocuments(array $rows): int
    {
        $n = 0;
        foreach ($rows as $r) {
            try {
                foreach ($this->storage->documents($r['entity']) as $doc) {
                    $this->client->putFile('/orders/' . rawurlencode($r['entity']) . '/documents/' . $doc['kind'], $doc['bytes'], $doc['content_type']);
                }
                $this->storage->sent($r['id'], $r['version']);
                $n++;
            } catch (ApiException $e) {
                $this->failed($r, $e);
            }
        }
        return $n;
    }

    /**
     * Отметка `through`: у сайта нет неотправленных изменений заказов с номером до неё. Заказы этого же запроса не
     * считаются: они уходят вместе с отметкой.
     *
     * @param list<string> $sending
     */
    private function through(array $sending): string
    {
        $min = $this->storage->minPendingOrder($sending);
        return $min !== null ? (string)max(0, (int)$min - 1) : $this->data->lastOrderId();
    }

    /** @param array<string, mixed> $r */
    private function failed(array $r, ApiException $e): void
    {
        $attempts = $r['attempts'] + 1;
        if (!$e->temporary() && $e->status !== 401 && $e->errorCode !== 'paused') {
            $this->say("{$r['kind']} #{$r['entity']}: EasySeller отказал: " . $e->getMessage());
            $this->storage->close($r['id'], $e->getMessage());
            return;
        }
        if (time() - $r['created_at'] >= self::GIVE_UP) {
            $this->say("{$r['kind']} #{$r['entity']}: закрыто после суток попыток: " . $e->getMessage());
            $this->storage->close($r['id'], $e->getMessage());
            return;
        }
        $wait = $e->retryAfter !== null ? max(60, $e->retryAfter) : 60 * (self::BACKOFF[$attempts] ?? 60);
        $this->storage->retry($r['id'], $attempts, time() + $wait, $e->getMessage());
    }

    /**
     * Строки пачками: до 200 и до ~900 КБ (предел EasySeller 1 МБ).
     *
     * @param list<array<string, mixed>> $items
     * @return list<list<array<string, mixed>>>
     */
    private function fit(array $items): array
    {
        $parts = [];
        $part = [];
        $bytes = 0;
        foreach ($items as $item) {
            $size = strlen(self::json($item)) + 1;
            if ($part !== [] && (count($part) >= self::BATCH || $bytes + $size > self::MAX_BYTES)) {
                $parts[] = $part;
                $part = [];
                $bytes = 0;
            }
            $part[] = $item;
            $bytes += $size;
        }
        if ($part !== []) {
            $parts[] = $part;
        }
        return $parts;
    }

    /** Адрес отправителя вебхука среди адресов EasySeller. */
    private function trusted(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }
        $this->refreshIps(false);
        $list = array_filter(explode(',', (string)$this->storage->getState('webhook_ips')));
        return in_array($ip, $list, true);
    }

    /** Список адресов из `GET /site` раз в сутки; без списка не чаще раза в минуту. */
    private function refreshIps(bool $force): void
    {
        $at = (int)$this->storage->getState('webhook_ips_at');
        $have = (string)$this->storage->getState('webhook_ips') !== '';
        if (!$force && $at > time() - ($have ? self::IPS_EVERY : 60)) {
            return;
        }
        $this->storage->setState('webhook_ips_at', (string)time());
        try {
            $ips = (array)($this->client->get('/site')['data']['webhook_ips'] ?? []);
            $ips = array_values(array_filter($ips, fn($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)));
            if ($ips !== []) {
                $this->storage->setState('webhook_ips', implode(',', $ips));
            }
        } catch (ApiException $e) {
            $this->say('адреса EasySeller: ' . $e->getMessage());
        }
    }

    private static function kindOf(string $what): string
    {
        return ['products' => self::PRODUCT, 'orders' => self::ORDER][$what] ?? $what;
    }

    private function say(string $text): void
    {
        if ($this->log !== null) {
            ($this->log)($text);
        }
    }

    private static function json(mixed $data): string
    {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
