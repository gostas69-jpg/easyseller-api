<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk;

/**
 * Итог изменения для EasySeller: `applied` (записано), `checked` (запись на сайте в режиме проверки, ничего не
 * изменено), `rejected` (отказ с текстом для продавца и полем), `conflict` (версия на сайте новее; свежая запись в
 * `result`).
 */
final class Result
{
    public const APPLIED = 'applied';
    public const CHECKED = 'checked';
    public const REJECTED = 'rejected';
    public const CONFLICT = 'conflict';
    /** Внутреннее: просьба `resync` ещё отправляется, ответа пока нет. */
    public const WAIT = 'wait';

    /** @param array<string, mixed>|null $result */
    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?string $field = null,
        public readonly ?array $result = null,
    ) {
    }

    /** @param array<string, mixed>|null $result свежая запись: `['product' => …]` или `['warehouse' => …]` */
    public static function applied(?array $result = null): self
    {
        return new self(self::APPLIED, null, null, $result);
    }

    public static function checked(): self
    {
        return new self(self::CHECKED);
    }

    public static function rejected(string $message, ?string $field = null): self
    {
        return new self(self::REJECTED, $message, $field);
    }

    /** @param array<string, mixed>|null $result */
    public static function conflict(string $message, ?array $result = null): self
    {
        return new self(self::CONFLICT, $message, null, $result);
    }

    public static function wait(): self
    {
        return new self(self::WAIT);
    }

    /** @return array<string, mixed> строка результата протокола (без `id`) */
    public function toArray(): array
    {
        return array_filter(['status' => $this->status, 'message' => $this->message, 'field' => $this->field, 'result' => $this->result], fn($v) => $v !== null);
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self((string)($row['status'] ?? self::REJECTED), $row['message'] ?? null, $row['field'] ?? null, $row['result'] ?? null);
    }
}
