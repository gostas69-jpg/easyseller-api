<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk;

/**
 * Ошибка EasySeller в форме протокола (`{"ok":false,"error":{"code","message"}}`) или сети (статус 0). `temporary()`:
 * повторить позже (сеть, 429, 5xx, заказ ещё не дошёл до EasySeller); иначе повтор не поможет.
 */
final class ApiException extends \RuntimeException
{
    /** @param array<string, mixed> $error тело ошибки целиком (`fields`, `current` и т. п.) */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly array $error = [],
    ) {
        parent::__construct($message);
    }

    public function temporary(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500 || $this->errorCode === 'order_unknown';
    }
}
