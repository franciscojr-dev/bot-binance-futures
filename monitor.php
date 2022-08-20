<?php

use IBotMex\Api\{
    Request,
    Configurations as ConfigRequest
};
use IBotMex\Core\{
    Monitor,
    DB,
    Configurations as ConfigMonitor
};
use React\EventLoop\Loop;

require __DIR__ . '/vendor/autoload.php';

$options = getopt('s::', ['symbol::']);
$runSymbol = $options['symbol'] ?? '';
$runDelay = $options['delay'] ?? 1;
$symbols = glob(__DIR__ . "/configs/monitor_*{$runSymbol}.ini");
$delayLoop = 0;
$delayIncrement = 1;
$increment = 0;

if (empty($runSymbol)) {
    foreach ($symbols as $symbol) {
        $configs = parse_ini_file($symbol, true, INI_SCANNER_RAW);

        if ($increment > 0 && $increment % 5 === 0) {
            $delayLoop = $delayIncrement;
            $delayIncrement += 0.5;
        }

        $delayLoop += $delayIncrement;
        $increment++;

        shell_exec("/usr/bin/php monitor.php --symbol={$configs['operation']['symbol']} --delay={$delayLoop} >> monitor.log&");
        sleep($delayLoop);
    }

    exit(0);
}

$authInfo = parse_ini_file(__DIR__ . '/configs/auth.ini', true, INI_SCANNER_RAW);

$request = new Request(new ConfigRequest([
    'public_key' => $authInfo['info']['public_key'],
    'private_key' => $authInfo['info']['private_key'],
    'mode' => $authInfo['info']['mode'],
]));
$loop = Loop::get();

foreach ($symbols as $symbol) {
    $configs = parse_ini_file($symbol, true, INI_SCANNER_RAW);

    $loop->addPeriodicTimer($runDelay, function () use ($request, $configs) {
        $db = new DB(__DIR__ . '/db/bot.db');
        $db->busyTimeout(5e4);

        $monitor = new Monitor(new ConfigMonitor([
            'symbol' => $configs['operation']['symbol'],
            'side' => $configs['operation']['side'],
            'profit' => $configs['operation']['profit'],
            'leverage' => $configs['operation']['leverage'],
            'scalper' => $configs['operation']['scalper'],
            'loss_position' => $configs['operation']['loss_position'],
            'close_position' => $configs['operation']['close_position'],
            'order_contracts' => $configs['operation']['order_contracts'],
            'max_contracts' => $configs['operation']['max_contracts'],
            'max_orders' => $configs['operation']['max_orders'],
            'timeout_order' => $configs['operation']['timeout_order'],
        ]), $request, $db);
        $monitor->setDebug(true);

        $db->exec(sprintf("UPDATE monitor SET execution = '%s'", date('Y-m-d H:i:s')));

        echo $monitor->textColor('white', str_repeat('-', 60).PHP_EOL);

        if ($monitor->isMonitoring()) {
            printf(
                "[%s] - Monitoring %s[%s]\n",
                date('Y-m-d H:i:s'),
                $monitor->textColor('yellow', $configs['operation']['symbol']),
                $monitor->textColor('magenta', $configs['operation']['max_contracts'])
            );

            $monitor->init();
        } else {
            printf(
                "[%s] - %s %s...\n",
                date('Y-m-d H:i:s'),
                $monitor->textColor('blue', "Waiting funding"),
                $monitor->textColor('yellow', $configs['operation']['symbol'])
            );

            sleep(10);
        }

        $db->close();
        unset($db);
    });
}

$loop->run();
