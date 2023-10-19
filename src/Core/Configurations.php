<?php

namespace IBotMex\Core;

final class Configurations
{
    private $symbol = null;
    private $side = null;
    private $leverage = null;
    private $close_position = null;
    private $order_contracts = null;

    public function __construct(array $configs)
    {
        if ($this->validator($configs)) {
            $this->symbol = $configs['symbol'];
            $this->side = $configs['side'];
            $this->leverage = $configs['leverage'];
            $this->close_position = $configs['close_position'];
            $this->order_contracts = $configs['order_contracts'];
        } else {
            throw new \Exception("Values incorrects!");
        }
    }

    private function __clone() {}

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getSide(): string
    {
        return $this->side;
    }

    public function getLeverage(): int
    {
        return $this->leverage;
    }

    public function getClosePosition(): int
    {
        return $this->close_position;
    }

    public function getOrderContracts(): float
    {
        return $this->order_contracts;
    }

    private function validator(array $configs): bool
    {
        if (false === $this->keyExists([
            'symbol',
            'side',
            'leverage',
            'close_position',
            'order_contracts',
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
}
