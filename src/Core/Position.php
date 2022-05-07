<?php

namespace IBotMex\Core;

trait Position
{
    public static function percentage(float $value1, float $value2): float
    {
        $dif = bcsub($value1, $value2, 5);
        $div = bcdiv($dif, $value1, 5);

        return bcmul($div, 100, 2);
    }

    public static function percentageLeverage(float $value1, float $value2, int $leverage): float
    {
        return bcmul(Position::percentage($value1, $value2), $leverage, 2);
    }

    public static function calculeProfit(float $value, float $percentage): float
    {
        $percentage = bcdiv($percentage, 100, 3);
        return bcmul($value, $percentage, 4);
    }

    public static function calculeProfitOrder(float $profit, int $leverage): float
    {
        return bcdiv($profit, $leverage, 3);
    }

    public static function calculePriceOrder(string $type, float $value, float $profit, int $leverage): float
    {
        $marge = Position::calculeProfit($value, Position::calculeProfitOrder($profit, $leverage));
        return Position::valueMarge($type, $value, $marge);
    }

    private static function getTotalDecimal(float $value): int
    {
        preg_match('/\.(?<decimal>[0-9]+)/', $value, $numbers);

        if (!isset($numbers['decimal'])) {
            return 2;
        }

        return strlen($numbers['decimal']);
    }

    private static function valueMarge(string $type, float $value, float $marge): float
    {
        $decimal = Position::getTotalDecimal($value);

        switch ($type) {
            case 'sell':
                $value = bcsub($value, $marge, $decimal);
                break;
            case 'buy':
                $value = bcadd($value, $marge, $decimal);
                break;
        }

        return $value;
    }

    public static function numberFormat(float $number): string
    {
        return preg_replace('/([0-9]+)([0-9]{2}$)/', '$1.$2', $number * 100);
    }

    public static function typeOrder(float $value): string
    {
        if ($value == 0) {
            return '';
        }

        if ($value < 0) {
            return 'sell';
        }

        return 'buy';
    }

    public static function arrayKeyLast(array $array): ?int
    {
        return array_keys($array)[count($array)-1];
    }
}
