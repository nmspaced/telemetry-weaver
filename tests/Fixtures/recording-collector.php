<?php

declare(strict_types=1);

// A local HTTP endpoint that answers at once and prints the path of every request it received.
$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    exit(1);
}

fwrite(STDOUT, (string) stream_socket_get_name($server, false) . PHP_EOL);
fflush(STDOUT);
$until = microtime(true) + 5;
while (microtime(true) < $until) {
    $ready = [$server];
    $none = [];
    if (stream_select($ready, $none, $none, 1) !== 1) {
        continue;
    }

    $connection = stream_socket_accept($server, 1);
    if ($connection === false) {
        continue;
    }

    $head = (string) fread($connection, 65_536);
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection);
    $match = [];
    preg_match('#^POST (\S+)#', $head, $match);
    fwrite(STDOUT, ($match[1] ?? '?') . PHP_EOL);
    fflush(STDOUT);
}

fclose($server);
