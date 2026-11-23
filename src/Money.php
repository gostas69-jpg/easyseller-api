<?php

declare(strict_types=1);

namespace EasySeller\SiteSdk;

/**
 * Деньги заказа в форме протокола (`Order.money`): суммы строками с двумя знаками, у расходов НДС внутри суммы.
 *
 * Сайт знает, есть ли НДС в цене перевозчика и платёжной системы: он заключал договоры. Если сайт отдаёт `vat`
 * числом, EasySeller берёт его как есть и свой переключатель «С НДС / Без НДС» для этого расхода не применяет.
 * `vat: null` значит «сайт не знает», тогда решает переключатель в EasySeller.
 */
final class Money
{
    /** Сумма строкой протокола: «6500.00». */
    public static function amount(float $value): string
    {
        $s = number_format(round($value, 2), 2, '.', '');
        return $s === '-0.00' ? '0.00' : $s;
    }

    /**
     * Расход `{amount, vat}`: доставка, страховка, комиссия за оплату.
     *
     * @param bool|null  $vatIncluded есть ли НДС в сумме; null — сайт не знает
     * @param float|null $vatRate     ставка НДС, % (16); нужна, только если НДС в сумме есть
     * @return array{amount: string, vat: ?string}
     */
    public static function cost(float $amount, ?bool $vatIncluded, ?float $vatRate = null): array
    {
        $vat = null;
        if ($vatIncluded === false) {
            $vat = 0.0;
        } elseif ($vatIncluded === true && $vatRate !== null && $vatRate > 0) {
            $vat = $amount * $vatRate / (100 + $vatRate);
        }
        return ['amount' => self::amount($amount), 'vat' => $vat === null ? null : self::amount($vat)];
    }

    /**
     * Цена перевозчику `delivery_cost`: то же, что `cost()`, и отметка `estimate` (true — расчёт при оформлении,
     * false — итог из отправления перевозчика).
     *
     * @return array{amount: string, vat: ?string, estimate: bool}
     */
    public static function deliveryCost(float $amount, ?bool $vatIncluded, ?float $vatRate = null, bool $estimate = true): array
    {
        return self::cost($amount, $vatIncluded, $vatRate) + ['estimate' => $estimate];
    }

    /**
     * Комиссия за оплату `payment_fee` по ставке платёжной системы, через которую прошли деньги.
     * Не оплачен заказ: передайте `$paid = false`, будет ноль. Ставки у разных платёжных систем разные: каждую
     * держите в настройках своего модуля оплаты, не одну на сайт.
     *
     * @param float $orderTotal сумма, которую оплатил покупатель
     * @param float $ratePercent ставка комиссии, % (0.95)
     * @return array{amount: string, vat: ?string}
     */
    public static function paymentFee(float $orderTotal, float $ratePercent, bool $paid, ?bool $vatIncluded, ?float $vatRate = null): array
    {
        return self::cost($paid ? $orderTotal * $ratePercent / 100 : 0.0, $vatIncluded, $vatRate);
    }
}
