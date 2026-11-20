<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk;

/**
 * Как библиотека ходит в сеть. По умолчанию curl или потоки PHP (HttpTransport); свой можно подставить, если у движка
 * свой HTTP-клиент или нужен прокси.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string} заголовки с именами в нижнем регистре;
     *         status 0: соединиться не удалось
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array;
}
