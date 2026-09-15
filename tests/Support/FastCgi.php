<?php

declare(strict_types=1);

namespace Nmspaced\TelemetryWeaver\Tests\Support;

use PHPUnit\Framework\Assert;

/** Minimal single-request FastCGI client for the real FPM lifecycle test. */
final class FastCgi
{
    public static function request(string $socket, string $script, string $query): string
    {
        $stream = \stream_socket_client('unix://' . $socket, timeout: 3);
        Assert::assertIsResource($stream);
        \stream_set_timeout($stream, 3);
        $params = '';
        foreach ([
            'SCRIPT_FILENAME' => $script,
            'REQUEST_METHOD' => 'GET',
            'QUERY_STRING' => $query,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ] as $key => $value) {
            $params .= \pack('NN', \strlen($key) | 0x8000_0000, \strlen($value) | 0x8000_0000) . $key . $value;
        }

        $request =
            self::record(1, \pack('nCxxxxx', 1, 0))
            . self::record(4, $params)
            . self::record(4, '')
            . self::record(5, '');
        Assert::assertSame(\strlen($request), \fwrite($stream, $request));
        $output = '';
        try {
            do {
                $header = \unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', self::read($stream, 8));
                Assert::assertIsArray($header);
                $length = $header['length'] ?? Assert::fail('Missing length');
                $padding = $header['padding'] ?? Assert::fail('Missing padding');
                $type = $header['type'] ?? Assert::fail('Missing type');
                Assert::assertIsInt($length);
                Assert::assertIsInt($padding);
                $body = self::read($stream, $length + $padding);
                if ($type === 6) {
                    $output .= \substr($body, 0, $length);
                }
            } while ($type !== 3);
        } finally {
            \fclose($stream);
        }

        return $output;
    }

    private static function record(int $type, string $body): string
    {
        return \pack('CCnnCC', 1, $type, 1, \strlen($body), 0, 0) . $body;
    }

    /** @param resource $stream */
    private static function read(mixed $stream, int $length): string
    {
        $result = '';
        while (\strlen($result) < $length) {
            $chunk = \fread($stream, $length - \strlen($result));
            Assert::assertIsString($chunk);
            Assert::assertNotSame('', $chunk, 'FPM closed or timed out before END_REQUEST');
            $result .= $chunk;
        }

        return $result;
    }
}
