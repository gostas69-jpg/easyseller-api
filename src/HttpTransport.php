<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk;

/** Запросы через curl, без него через потоки PHP (`file_get_contents` с контекстом). */
final class HttpTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        return function_exists('curl_init')
            ? $this->curl($method, $url, $headers, $body, $timeout)
            : $this->stream($method, $url, $headers, $body, $timeout);
    }

    private function curl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $out = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => $k . ': ' . $v, array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$out): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $out[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = $response === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $out, 'body' => $response === false ? '' : (string)$response];
    }

    private function stream(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", array_map(fn($k, $v) => $k . ': ' . $v, array_keys($headers), $headers)),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        $out = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                $out = [];
            } elseif (($p = strpos($line, ':')) !== false) {
                $out[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
        }
        return ['status' => $response === false && $status === 0 ? 0 : $status, 'headers' => $out, 'body' => $response === false ? '' : $response];
    }
}
