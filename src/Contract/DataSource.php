<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk\Contract;

/**
 * Данные сайта в форме протокола (openapi.yaml: Product, Order, Warehouse, SiteProperty, PutSiteRequest). Библиотека
 * сама режет их на пачки, отправляет и повторяет; сайт только отдаёт текущее состояние.
 */
interface DataSource
{
    /**
     * Сведения о сайте для `PUT /site`: `module`, `capabilities`, `modes`, `currency`, `timezone`, `site`, `prices`.
     * `protocol` библиотека ставит сама.
     *
     * @return array<string, mixed>
     */
    public function site(): array;

    /**
     * Модели по номерам: строка протокола `Product`; удалённая модель `['id' => …, 'deleted' => true, 'version' => …]`;
     * номера, которых сайт не знает, просто пропустите.
     *
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    public function products(array $ids): array;

    /**
     * Заказы по номерам (`Order.id`, внутренний номер заказа сайта): строка протокола `Order`.
     *
     * @param list<string> $ids
     * @return list<array<string, mixed>>
     */
    public function orders(array $ids): array;

    /** @return list<array<string, mixed>> все склады сайта (пустой список: складов не ведёте) */
    public function warehouses(): array;

    /** @return list<array<string, mixed>> остатки складов `{warehouse, variant_id, quantity}` */
    public function stocks(): array;

    /** @return list<array<string, mixed>> справочник характеристик модели `SiteProperty` */
    public function properties(): array;

    /**
     * Номера моделей (`product`) или заказов (`order`), изменённых с `since` (null: все). Для сверки раз в час и
     * просьбы EasySeller «пришлите заново».
     *
     * @return iterable<string>
     */
    public function changedIds(string $kind, ?\DateTimeImmutable $since): iterable;

    /** Наибольший номер заказа сайта (для отметки `through`), нет заказов: '0'. */
    public function lastOrderId(): string;
}
