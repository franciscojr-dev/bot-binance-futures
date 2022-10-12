<?php

namespace IBotMex\Api;

final class Request
{
    const VERSION = '1.0.0';
    const URL_MAIN = 'https://fapi.binance.com';
    const URL_TESTNET = 'https://testnet.binancefuture.com';
    const PATH = '/fapi/v1';

    private $timeout = 5;
    private $configs = null;
    private $ch = null;
    private $url_base = null;

    public function __construct(Configurations $configs)
    {
        $this->configs = $configs;
        $this->init();
    }

    public function __destruct()
    {
        curl_close($this->ch);
    }

    private function __clone() {}

    private function init(): void
    {
        $this->mode();
        $this->ch = curl_init();
    }

    private function mode(): void
    {
        switch ($this->configs->getMode()) {
            case 'test':
                $url = Request::URL_TESTNET;
                break;
            case 'main':
                $url = Request::URL_MAIN;
                break;
            default:
                $url = '';
        }

        $this->url_base = $url;
    }

    private function request(string $verb, string $path, array $data): array
    {
        $path = sprintf('%s/%s', Request::PATH, preg_replace('/^[\/]+/', '', $path));
        $this->setOptions([
            'url' => sprintf('%s%s', $this->url_base, $path),
            'path' => $path,
            //'data' => json_encode($data),
            'data' => $data,
            'verb' => $verb
        ]);

        return $this->response();
    }

    private function setOptions(array $vars): void
    {
        $vars['data']['timestamp'] = time() * 1e3;
        $vars['data']['recvWindow'] = 60000;
        $params = http_build_query($vars['data']);
        $params .= '&signature=' . $this->signature($vars['data']);
        $vars['url'] .= '?'.$params;

        curl_setopt_array($this->ch, [
            CURLOPT_URL => $vars['url'],
            CURLOPT_USERAGENT => 'iBotMex/'.Request::VERSION,
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $vars['verb'],
            CURLOPT_POST => true,
            // CURLOPT_POSTFIELDS => $vars['data'],
            CURLOPT_HTTPHEADER => $this->headers($vars)
        ]);
    }

    private function headers(array $vars): array
    {
        return [
            "Content-Type: application/json; charset=utf-8",
            "X-MBX-APIKEY: {$this->configs->getPublicKey()}"
        ];
    }

    public function get(string $path, array $data): array
    {
        return $this->request('GET', $path, $data);
    }

    public function post(string $path, array $data): array
    {
        return $this->request('POST', $path, $data);
    }

    public function delete(string $path, array $data): array
    {
        return $this->request('DELETE', $path, $data);
    }

    public function put(string $path, array $data): array
    {
        return $this->request('PUT', $path, $data);
    }

    private function response(): array
    {
        $header = $this->header();

        if (empty($header)) {
            return [];
        }

        $response = [
            'status' => curl_getinfo($this->ch, CURLINFO_HTTP_CODE),
            'response' => json_decode($header['body'], true),
            'orders' => $this->orders($header['header']),
            'rate_limit' => $this->rateLimit($header['header'])
        ];

        return $response;
    }

    private function header(): array
    {
        $response = curl_exec($this->ch);
        preg_match('/(?<header>[\w\W]+)\s\n(?<body>.*)$/', $response, $header);

        return $header;
    }

    private function orders(string $header): array
    {
        preg_match_all('/X\-MBX\-ORDER\-COUNT\-(.*[^\s])/i', $header, $temp);
        $orders = [];

        foreach ($temp[1] as $value) {
            list($key, $value) = explode(': ', $value);
            $orders[$key] = $value;
        }

        return $orders;
    }

    private function rateLimit(string $header): array
    {
        preg_match_all('/X\-MBX\-USED\-WEIGHT\-(.*[^\s])/i', $header, $temp);
        $rate_limit = [];

        foreach ($temp[1] as $value) {
            list($key, $value) = explode(': ', $value);
            $rate_limit[$key] = $value;
        }

        return $rate_limit;
    }

    private function signature(array $data): string
    {
        return hash_hmac('sha256', http_build_query($data), $this->configs->getPrivateKey());
    }

    public function setTimeout(int $time): void
    {
        $this->timeout = $time;
    }
}
