<?php

namespace IBotMex\Core;

final class Configurations
{
    private $symbol = null;
    private $side = null;
    private $profit = null;
    private $leverage = null;
    private $scalper = null;
    private $loss_position = null;
    private $close_position = null;
    private $order_contracts = null;
    private $max_contracts = null;
    private $max_orders = null;
    private $timeout_order = null;

    public function __construct(array $configs)
    {
        if ($this->validator($configs)) {
            $this->symbol = $configs['symbol'];
            $this->side = $configs['side'];
            $this->profit = $configs['profit'];
            $this->leverage = $configs['leverage'];
            $this->scalper = $configs['scalper'];
            $this->loss_position = $configs['loss_position'];
            $this->close_position = $configs['close_position'];
            $this->order_contracts = $configs['order_contracts'];
            $this->max_contracts = $configs['max_contracts'];
            $this->max_orders = $configs['max_orders'];
            $this->timeout_order = $configs['timeout_order'];
        } else {
            throw new \Exception("Values incorrects!");
        }
    }

    private function __clone() {}

    private function validator(array $configs): bool
    {
        if (false === $this->keyExists([
            'symbol',
            'side',
            'profit',
            'leverage',
            'scalper',
            'loss_position',
            'close_position',
            'order_contracts',
            'max_contracts',
            'max_orders',
            'timeout_order',
        ], $configs)) {
            return false;
        }

        if (false === $this->isEmpty($configs)) {
            return true;
        }

        return false;
    }

    private function keyExists(array $keys, array $array): bool
    {
        foreach ($keys as $key) {
            if (false === array_key_exists($key, $array)) {
                return false;
            }
        }

        return true;
    }

    private function isEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if ('' === trim($value)) {
                return true;
            }
        }

        return false;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getSide(): string
    {
        return $this->side;
    }

    public function getProfit(): float
    {
        return $this->profit;
    }

    public function getLeverage(): int
    {
        return $this->leverage;
    }

    public function getScalper(): int
    {
        return $this->scalper;
    }

    public function getLossPosition(): float
    {
        return $this->loss_position;
    }

    public function getClosePosition(): int
    {
        return $this->close_position;
    }

    public function getOrderContracts(): float
    {
        return $this->order_contracts;
    }

    public function getMaxContracts(): float
    {
        return $this->max_contracts;
    }

    public function getMaxOrders(): int
    {
        return $this->max_orders;
    }

    public function getTimeoutOrder(): int
    {
        return $this->timeout_order;
    }
}
