<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk;

/**
 * Клиент API EasySeller для сайта, протокол 2: `https://easyseller.kz/api/site/v2/`, ключ API магазина
 * (`es_site_…`) в `Authorization: Bearer`. Ответ: разобранный JSON при `{"ok": true}`, иначе ApiException.
 */
final class Client
{
    public const DEFAULT_BASE = 'https://easyseller.kz/api/site/v2';
    public const VERSION = '1.1.1';

    private Transport $transport;

    public function __construct(
        private readonly string $token,
        private readonly string $base = self::DEFAULT_BASE,
        ?Transport $transport = null,
        private readonly int $timeout = 20,
    ) {
        $this->transport = $transport ?? new HttpTransport();
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''), null, 'application/json');
    }

    /** @return array<string, mixed> */
    public function put(string $path, array $body): array
    {
        return $this->request('PUT', $path, self::json($body), 'application/json');
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, self::json($body), 'application/json');
    }

    /** Файл телом запроса (печатная форма заказа). @return array<string, mixed> */
    public function putFile(string $path, string $bytes, string $contentType): array
    {
        return $this->request('PUT', $path, $bytes, $contentType);
    }

    /** Файл EasySeller (фото для правки карточки). @return array{bytes: string, contentType: string} */
    public function download(string $path): array
    {
        $r = $this->transport->send('GET', $this->url($path), $this->headers(null), null, $this->timeout);
        if ($r['status'] === 0) {
            throw new ApiException(0, 'network', 'EasySeller не отвечает');
        }
        if ($r['status'] !== 200) {
            throw new ApiException($r['status'], 'download', 'EasySeller не отдал файл: ответ ' . $r['status']);
        }
        return ['bytes' => $r['body'], 'contentType' => strtolower(trim(explode(';', $r['headers']['content-type'] ?? '')[0]))];
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, ?string $body, string $type): array
    {
        if ($this->token === '') {
            throw new ApiException(0, 'not_configured', 'Нет ключа API EasySeller');
        }
        $r = $this->transport->send($method, $this->url($path), $this->headers($body !== null ? $type : null), $body, $this->timeout);
        if ($r['status'] === 0) {
            throw new ApiException(0, 'network', 'EasySeller не отвечает');
        }
        $json = json_decode($r['body'], true);
        if ($r['status'] >= 200 && $r['status'] < 300 && is_array($json) && ($json['ok'] ?? null) === true) {
            return $json;
        }
        $error = is_array($json) && is_array($json['error'] ?? null) ? $json['error'] : [];
        $retry = (int)($r['headers']['retry-after'] ?? 0);
        throw new ApiException(
            $r['status'],
            (string)($error['code'] ?? 'http'),
            (string)($error['message'] ?? ('EasySeller ответил ' . $r['status'])),
            $retry > 0 ? $retry : null,
            $error,
        );
    }

    /** @return array<string, string> */
    private function headers(?string $type): array
    {
        $h = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
            'User-Agent' => 'easyseller-site-sdk/' . self::VERSION,
        ];
        if ($type !== null) {
            $h['Content-Type'] = $type;
        }
        return $h;
    }

    private function url(string $path): string
    {
        return rtrim($this->base, '/') . '/' . ltrim($path, '/');
    }

    private static function json(array $body): string
    {
        return (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
