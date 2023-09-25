<?php

require './vendor/autoload.php';

use IBotMex\Core\DB;

$config = parse_ini_file(__DIR__ . '/configs/monitor.ini', true, INI_SCANNER_RAW);
$listSymbols = parse_ini_file(__DIR__ . '/configs/allow_symbols.ini', true, INI_SCANNER_RAW);

if (!($config['monitor']['use_ws'] ?? 0)) {
    exit(0);
}

$candleTime = $config['monitor']['candle_time'] ?? '15m';
$candleLimit = $config['monitor']['candle_limit'] ?? 5;
$klineTime = 'continuousKline_'.$candleTime;

foreach ($listSymbols['symbols'] as $symbol) {
    $symbols[] = strtolower($symbol)."_perpetual@{$klineTime}";
}

$symbols = implode('/', $symbols);

loopWs();

function loopWs(): void {
    try {
        initWs();
    } catch (Exception $e) {
        loopWs();

        echo $e->getMessage() . PHP_EOL;
    }
}

function initWs(): void {
    global $symbols;

    $db = new DB(__DIR__ . '/db/bot.db');
    $db->busyTimeout(5e4);
    $client = new WebSocket\Client('wss://fstream.binance.com/stream?streams='.$symbols);

    while (true) {
        $response = json_decode($client->receive(), true);
        $kline = $response['data']['k'];

        $start = date('Y-m-d H:i:s', (int) ($kline['t']) / 1000);
        $close = date('Y-m-d H:i:s', (int) ($kline['T']) / 1000);
        $updated = date('Y-m-d H:i:s', (int) ($response['data']['E']) / 1000);

        /*
        printf(
            "Symbol: %s - Open: %s - Close: %s - High: %s - Low: %s - Start: %s - Close: %s\n",
            $response['data']['ps'], $kline['o'], $kline['c'], $kline['h'], $kline['l'], $start, $close
        );
        */

        $db->exec(
            sprintf(
                "INSERT or REPLACE INTO symbol (
                    name, open, close, high, low, open_at, close_at, updated_at
                ) VALUES ('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')",
                $response['data']['ps'],
                $kline['o'],
                $kline['c'],
                $kline['h'],
                $kline['l'],
                $start,
                $close,
                $updated
            )
        );

        usleep(10);
    }

    $client->close();
    $db->close();
}

