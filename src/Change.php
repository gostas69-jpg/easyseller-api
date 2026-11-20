<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk;

/**
 * Изменение для сайта из EasySeller: `product.patch`, `price.set`, `stock.set`, `warehouse.save`, `order.cancel`,
 * `order.ship`, `resync`. `data` по виду, схемы в протоколе (openapi.yaml, `Change`).
 */
final class Change
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly array $data,
        public readonly ?string $at = null,
    ) {
    }

    /** @param array<string, mixed> $row строка `items` из `GET /changes` или вебхука */
    public static function fromArray(array $row): ?self
    {
        $id = (int)($row['id'] ?? 0);
        return $id > 0 ? new self($id, (string)($row['type'] ?? ''), is_array($row['data'] ?? null) ? $row['data'] : [], isset($row['at']) ? (string)$row['at'] : null) : null;
    }
}
