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
$symbols = glob(__DIR__ . "/configs/*{$runSymbol}.ini");
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

$request = new Request(new ConfigRequest([
    'public_key' => 'nyCC0Fgu3B9rVNSCIfcRtbfb4pVJaJlQX8l7Zv5I24FADuXihUhmfQSQ3yfkIQEb',
    'private_key' => 'SsPNLZGZz6xpDIJ5WBu1SclZz4dHvexLfE2S0kBloi5UokewHULi7Ll5o3odJhiW',
    'mode' => 'main'
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
        $monitor->setOperations(true);
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
                $monitor->textColor('blue', "[%s] - Waiting funding %s...\n"),
                date('Y-m-d H:i:s'),
                $monitor->textColor('yellow', $configs['operation']['symbol'])
            );
        }

        $db->close();
        unset($db);
    });
}

$loop->run();
