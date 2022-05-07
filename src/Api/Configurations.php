<?php

namespace IBotMex\Api;

final class Configurations
{
    private $public_key = null;
    private $private_key = null;
    private $mode = null;

    public function __construct(array $configs)
    {
        if ($this->validator($configs)) {
            $this->public_key = $configs['public_key'];
            $this->private_key = $configs['private_key'];
            $this->mode = $configs['mode'];
        } else {
            throw new \Exception("Values incorrects!");
        }
    }

    private function __clone() {}

    private function validator(array $configs): bool
    {
        if (false === $this->keyExists(['public_key', 'private_key', 'mode'], $configs)) {
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
            if (true === empty($value)) {
                return true;
            }
        }

        return false;
    }

    public function getPublicKey(): string
    {
        return $this->public_key;
    }

    public function getPrivateKey(): string
    {
        return $this->private_key;
    }

    public function getMode(): string
    {
        return $this->mode;
    }
}
