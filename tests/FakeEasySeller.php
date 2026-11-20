<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk\Tests;

use EasySeller\SiteSdk\Transport;

/** EasySeller на подмену: отвечает по маршрутам, запоминает запросы. */
final class FakeEasySeller implements Transport
{
    /** @var list<array{method: string, path: string, body: mixed, raw: ?string, type: ?string}> */
    public array $requests = [];
    /** @var list<array<string, mixed>> изменения для GET /changes */
    public array $changes = [];
    /** @var array<string, array{status: int, body: array<string, mixed>, headers?: array<string, string>}> ответ на маршрут целиком */
    public array $override = [];
    /** @var list<string> */
    public array $webhookIps = ['176.12.79.195'];
    /** @var array<string, true> номера заказов, которых EasySeller не знает (409 order_unknown) */
    public array $unknownOrders = [];

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $path = substr($path, strlen('/api/site/v2'));
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $json = $body !== null ? json_decode($body, true) : null;
        $this->requests[] = ['method' => $method, 'path' => $path, 'body' => $json ?? $query, 'raw' => $body, 'type' => $headers['Content-Type'] ?? null];
        $key = $method . ' ' . $path;
        if (isset($this->override[$key])) {
            $o = $this->override[$key];
            return ['status' => $o['status'], 'headers' => $o['headers'] ?? [], 'body' => (string)json_encode($o['body'])];
        }
        if ($key === 'GET /changes') {
            $after = (int)($query['after'] ?? 0);
            $items = array_values(array_filter($this->changes, fn($c) => $c['id'] > $after));
            return $this->ok(['items' => array_slice($items, 0, (int)($query['limit'] ?? 100))]);
        }
        if ($key === 'GET /site') {
            return $this->ok(['data' => ['webhook_ips' => $this->webhookIps]]);
        }
        if ($method === 'PUT' && preg_match('#^/orders/([^/]+)/documents/#', $path, $m)) {
            return isset($this->unknownOrders[$m[1]])
                ? ['status' => 409, 'headers' => [], 'body' => (string)json_encode(['ok' => false, 'error' => ['code' => 'order_unknown', 'message' => 'нет заказа']])]
                : $this->ok([]);
        }
        if ($method === 'PUT' && is_array($json) && isset($json['items'])) {
            return $this->ok(['items' => array_map(fn($i) => ['id' => (string)($i['id'] ?? $i['code'] ?? ''), 'status' => 'ok'], $json['items'])]);
        }
        return $this->ok([]);
    }

    /** @return list<array<string, mixed>> */
    public function calls(string $method, string $path): array
    {
        return array_values(array_filter($this->requests, fn($r) => $r['method'] === $method && $r['path'] === $path));
    }

    private function ok(array $data): array
    {
        return ['status' => 200, 'headers' => [], 'body' => (string)json_encode(['ok' => true] + $data)];
    }
}
