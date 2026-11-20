<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk\Tests;

use EasySeller\SiteSdk\Change;
use EasySeller\SiteSdk\Contract\AbstractChangeHandler;
use EasySeller\SiteSdk\Contract\DataSource;
use EasySeller\SiteSdk\Result;

/** Сайт на подмену: товары и заказы в массивах, цены пишет в массив. */
final class FakeSite extends AbstractChangeHandler implements DataSource
{
    /** @var array<string, array<string, mixed>> */
    public array $products = [];
    /** @var array<string, array<string, mixed>> */
    public array $orders = [];
    /** @var array<string, string> */
    public array $prices = [];
    public bool $dry = false;
    public int $applied = 0;

    public function site(): array
    {
        return ['module' => 'test 1.0', 'capabilities' => ['products.read', 'orders.read', 'prices.write'], 'modes' => ['prices' => 'on'], 'currency' => 'KZT'];
    }

    public function products(array $ids): array
    {
        return array_values(array_intersect_key($this->products, array_flip($ids)));
    }

    public function orders(array $ids): array
    {
        return array_values(array_intersect_key($this->orders, array_flip($ids)));
    }

    public function warehouses(): array
    {
        return [['code' => 'main', 'name' => 'Склад']];
    }

    public function stocks(): array
    {
        return [['warehouse' => 'main', 'variant_id' => '1', 'quantity' => 5]];
    }

    public function properties(): array
    {
        return [];
    }

    public function changedIds(string $kind, ?\DateTimeImmutable $since): iterable
    {
        return array_map('strval', array_keys($kind === 'product' ? $this->products : $this->orders));
    }

    public function lastOrderId(): string
    {
        return $this->orders === [] ? '0' : (string)max(array_map('intval', array_keys($this->orders)));
    }

    protected function priceSet(Change $change): Result
    {
        if ($this->dry) {
            return Result::checked();
        }
        $this->applied++;
        $this->prices[(string)$change->data['variant_id']] = (string)$change->data['price'];
        return Result::applied();
    }
}
