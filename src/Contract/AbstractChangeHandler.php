<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk\Contract;

use EasySeller\SiteSdk\Change;
use EasySeller\SiteSdk\Result;

/** Разбор изменения по виду. Непереопределённый вид: отказ «Сайт этого не умеет». */
abstract class AbstractChangeHandler implements ChangeHandler
{
    public const UNSUPPORTED = 'Сайт этого не умеет: обновите модуль';

    public function handle(Change $change): Result
    {
        return match ($change->type) {
            'product.patch' => $this->productPatch($change),
            'price.set' => $this->priceSet($change),
            'stock.set' => $this->stockSet($change),
            'warehouse.save' => $this->warehouseSave($change),
            'order.cancel' => $this->orderCancel($change),
            'order.ship' => $this->orderShip($change),
            default => Result::rejected(self::UNSUPPORTED),
        };
    }

    /** Правка модели или варианта: `data.id` модель, `version`, поля правки. Фото `file:<номер>`: Client::download('/files/<номер>'). */
    protected function productPatch(Change $change): Result
    {
        return Result::rejected(self::UNSUPPORTED);
    }

    /** Цена варианта: `variant_id`, `price` («44990.00»), `old_price` (нет ключа: не трогать, null: стереть). */
    protected function priceSet(Change $change): Result
    {
        return Result::rejected(self::UNSUPPORTED);
    }

    /**
     * Остаток варианта на складе: `warehouse`, `variant_id`, `quantity`, `orders_seen_until`; иногда `shelf`, `buffer`,
     * `minimum`, `held` для пересчёта по своим резервам (протокол, глава 11).
     */
    protected function stockSet(Change $change): Result
    {
        return Result::rejected(self::UNSUPPORTED);
    }

    /** Новый склад (без `version`) или правка (с `code` и `version`). */
    protected function warehouseSave(Change $change): Result
    {
        return Result::rejected(self::UNSUPPORTED);
    }

    /** Отменить заказ `data.id` с причиной `data.reason`, как покупатель: до передачи перевозчику, оплаченный с возвратом. */
    protected function orderCancel(Change $change): Result
    {
        return Result::rejected(self::UNSUPPORTED);
    }

    /** «Товар пришёл»: передать перевозчику оплаченный заказ `data.id`, ждавший поставки. */
    protected function orderShip(Change $change): Result
    {
        return Result::rejected(self::UNSUPPORTED);
    }
}
