<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

/**
 * Serve deterministic responses on two loopback addresses at one ephemeral port.
 * Keep-alive exposes stale connection reuse. Large bodies exercise cURL's abort
 * behavior without external DNS, remote servers, or timing-based assertions.
 */
$first = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
throw_if($first === false, RuntimeException::class, $error);

$port = (int) parse_url('tcp://'.stream_socket_get_name($first, false), PHP_URL_PORT);
$second = stream_socket_server('tcp://127.0.0.2:'.$port, $errno, $error);
throw_if($second === false, RuntimeException::class, $error);

echo 'READY '.$port.PHP_EOL;
flush();

$listeners = [(int) $first => $first, (int) $second => $second];
$clients = [];
$buffers = [];

/** @param resource $socket */
$write = static function ($socket, string $bytes): bool {
    while ($bytes !== '') {
        $written = @fwrite($socket, $bytes);
        if ($written === false || $written === 0) {
            return false;
        }

        $bytes = mb_substr($bytes, $written, null, '8bit');
    }

    return true;
};

while (true) {
    $read = array_values($listeners + $clients);
    $writable = null;
    $except = null;
    if (stream_select($read, $writable, $except, 10) === 0) {
        break;
    }

    foreach ($read as $socket) {
        $key = (int) $socket;
        if (isset($listeners[$key])) {
            $client = stream_socket_accept($socket, 0);
            if ($client !== false) {
                stream_set_timeout($client, 5);
                $clients[(int) $client] = $client;
                $buffers[(int) $client] = '';
            }

            continue;
        }

        $chunk = fread($socket, 8192);
        if ($chunk === false || ($chunk === '' && feof($socket))) {
            fclose($socket);
            unset($clients[$key], $buffers[$key]);

            continue;
        }

        $buffers[$key] .= $chunk;
        if (! str_contains($buffers[$key], "\r\n\r\n")) {
            continue;
        }

        $path = explode(' ', $buffers[$key])[1];
        $buffers[$key] = '';

        if ($path === '/chunked') {
            $write($socket, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n");
            for ($index = 0; $index < 1024; $index++) {
                if (! $write($socket, "2000\r\n".str_repeat('x', 8192)."\r\n")) {
                    break;
                }
            }

            $write($socket, "0\r\n\r\n");

            continue;
        }

        $body = str_starts_with(stream_socket_get_name($socket, false), '127.0.0.1:') ? 'server-a' : 'server-b';
        $status = '200 OK';
        $headers = '';
        if ($path === '/redirect') {
            $status = '302 Found';
            $headers = "Location: /destination\r\n";
            $body = '';
        } elseif ($path === '/gzip') {
            $body = gzencode(str_repeat('x', 131072));
            $headers = "Content-Encoding: gzip\r\n";
        } elseif ($path === '/exact') {
            $body = 'é12';
        }

        $write($socket, 'HTTP/1.1 '.$status."\r\n".$headers.'Content-Length: '.mb_strlen($body, '8bit')."\r\nConnection: keep-alive\r\n\r\n".$body);
    }
}
