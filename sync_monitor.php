<?php

$serverList = [
    'srv-01.flinkbot.com' => true,
    'srv-02.flinkbot.com' => true,
    'srv-03.flinkbot.com' => false,
    'srv-04.flinkbot.com' => false,
    'srv-05.flinkbot.com' => false,
    'srv-06.flinkbot.com' => false,
    'srv-07.flinkbot.com' => false,
    'srv-08.flinkbot.com' => false,
];

$options = getopt('f::', ['files::']);
$syncFiles = $options['files'] ?? 'n';
$syncDb = $options['db'] ?? 'n';
$baseDir = '/home/botbinance/app';

echo "MONITOR SYNC STARTED...\n";
echo "------------------------\n";

foreach ($serverList as $host => $enable) {
    $attemps = 0;

    if ($enable) {
        echo "SYNC: {$host}\n";

        do {
            $connection = @ssh2_connect($host, 22);

            if (!$connection) {
                echo "CONNECTION FAIL!\n";
            }

            $attemps += 1;
        } while($attemps < 3);

        ssh2_auth_password($connection, 'root', '4h9aXGN8DN@vfgk');

        echo "FILE UPLOAD STARTED...\n";

        send_file('configs/monitor.ini');
        send_file('configs/allow_symbols.ini');

        if ($syncFiles === 'y') {
            cmd('service bot_binance_main stop');

            if ($syncDb === 'y') {
                send_file('db/bot.db');
            }

            send_file('monitor.php');
            send_file('start.php');
            send_file('src/Core/Monitor.php');
            send_file('src/Api/Request.php');
            send_file('composer.json');

            cmd('service bot_binance_main start');
        }

        echo "FILE UPLOAD COMPLETED...\n";
        echo "------------------------\n";

        ssh2_disconnect($connection);
    }
}

echo "MONITOR SYNC FINISHED...\n";

function send_file(string $file) {
    global $connection, $baseDir;

    ssh2_scp_send(
        $connection,
        __DIR__ . "/{$file}",
        $baseDir . "/{$file}",
        0644
    );
}

function cmd(string $cmd, bool $response = false) {
    global $connection;

    $stream = ssh2_exec($connection, $cmd);
    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);

    // Enable blocking for both streams
    stream_set_blocking($errorStream, true);
    stream_set_blocking($stream, true);

    $output = stream_get_contents($stream);
    $error = stream_get_contents($errorStream);

    if ($response) {
        echo "Output: {$output}\n";
    }

    if (!empty($error)) {
        echo "Error: {$error}\n";
    }
}
