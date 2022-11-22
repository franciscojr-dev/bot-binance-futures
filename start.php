<?php

use IBotMex\Core\DB;
use React\EventLoop\Loop;

require __DIR__ . '/vendor/autoload.php';

$loop = Loop::get();
$started = false;

$loop->addPeriodicTimer(10, function () use (&$started) {
    $db = new DB(__DIR__ . '/db/bot.db');
    $db->busyTimeout(5e4);

    $last_execution = $db->querySingle('SELECT execution FROM monitor ORDER BY execution DESC');
    $last_execution = new \Datetime($last_execution);
    $now = new DateTime('now');
    $diff = $now->format('U') - $last_execution->format('U');

    if (!$started || $diff >= 365) {
        if (!$started) {
            $started = true;

            echo "Starting...\n";

            shell_exec('/usr/bin/php monitor.php > monitor.log&');
        } else {
            echo "Restarting...\n";

            shell_exec('service bot_binance_main restart');
        }
    }

    $db->close();
    unset($db);
});

$loop->addPeriodicTimer(5*60, function () {
    shell_exec('truncate -s 0 monitor.log');
});


$loop->run();
