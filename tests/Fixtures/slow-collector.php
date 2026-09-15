<?php

declare(strict_types=1);

// An isolated local HTTP endpoint which accepts a connection but delays its response.
$server = stream_socket_server('tcp://127.0.0.1:0');
if ($server === false) {
    exit(1);
}

fwrite(STDOUT, (string) stream_socket_get_name($server, false) . PHP_EOL);
fflush(STDOUT);
$connection = stream_socket_accept($server, 5);
if ($connection !== false) {
    usleep(1_500_000);
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    fclose($connection);
}

fclose($server);
