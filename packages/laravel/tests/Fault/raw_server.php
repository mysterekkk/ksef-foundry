<?php

/**
 * Fault-injection HTTP server (REAL sockets, real HTTP/1.1 on 127.0.0.1). Not a mock: the SDK talks to it over TCP.
 *
 * Usage: php raw_server.php <portFile> <scriptFile> <logFile>
 *
 * scriptFile: JSON { "METHOD /path": [ response, response, ... ] }. Responses are consumed in order; the last one
 * repeats. response = { status, headers{}, body, delay (seconds), action: "reset"|"truncate"|"hang" }.
 * "reset" closes the socket without answering, "truncate" announces Content-Length 1000 but sends 10 bytes,
 * "hang" sleeps `delay` seconds and then closes. logFile gets one JSON line per request:
 * { method, path, query, bodyBytes, body, auth: sha256(Authorization header) | null, contentType, accept }.
 * GET /__shutdown stops the server.
 */

declare(strict_types=1);

[, $portFile, $scriptFile, $logFile] = $argv;
$script = json_decode((string) file_get_contents($scriptFile), true, 512, JSON_THROW_ON_ERROR);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
file_put_contents($portFile, (string) substr((string) strrchr((string) $name, ':'), 1));
$counters = [];

while (true) {
    $conn = stream_socket_accept($server, 120);
    if ($conn === false) {
        break;
    }
    stream_set_timeout($conn, 10);
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $head .= $chunk;
    }
    if (!str_contains($head, "\r\n\r\n")) {
        fclose($conn);
        continue;
    }
    [$rawHeaders, $body] = explode("\r\n\r\n", $head, 2);
    $lines = explode("\r\n", $rawHeaders);
    [$method, $target] = explode(' ', $lines[0]);
    $headers = [];
    foreach (array_slice($lines, 1) as $l) {
        if (str_contains($l, ':')) {
            [$n, $v] = explode(':', $l, 2);
            $headers[strtolower(trim($n))] = trim($v);
        }
    }
    $len = (int) ($headers['content-length'] ?? 0);
    while (strlen($body) < $len) {
        $chunk = fread($conn, $len - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    $path = (string) parse_url($target, PHP_URL_PATH);
    $query = (string) parse_url($target, PHP_URL_QUERY);

    if ($path === '/__shutdown') {
        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($conn);
        break;
    }

    file_put_contents($logFile, json_encode([
        'method' => $method,
        'path' => $path,
        'query' => $query,
        'bodyBytes' => strlen($body),
        'body' => $body,
        'auth' => isset($headers['authorization']) ? hash('sha256', $headers['authorization']) : null,
        'contentType' => $headers['content-type'] ?? null,
        'accept' => $headers['accept'] ?? null,
        'xErrorFormat' => $headers['x-error-format'] ?? null,
        'continuation' => $headers['x-continuation-token'] ?? null,
        'userAgent' => $headers['user-agent'] ?? null,
    ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

    $key = $method . ' ' . $path;
    $list = $script[$key] ?? null;
    if ($list === null) {
        $resp = ['status' => 404, 'body' => '{"title":"Not Found","status":404,"detail":"no script for ' . $key . '"}', 'headers' => ['Content-Type' => 'application/problem+json']];
    } else {
        $i = $counters[$key] ?? 0;
        $counters[$key] = $i + 1;
        $resp = $list[min($i, count($list) - 1)];
    }

    $action = $resp['action'] ?? null;
    if (isset($resp['delay'])) {
        usleep((int) ($resp['delay'] * 1_000_000));
    }
    if ($action === 'reset' || $action === 'hang') {
        // abortive close: RST instead of FIN where the platform allows it
        $sock = function_exists('socket_import_stream') ? socket_import_stream($conn) : null;
        if ($sock !== false && $sock !== null) {
            socket_set_option($sock, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
        }
        fclose($conn);
        continue;
    }
    $status = (int) ($resp['status'] ?? 200);
    $rbody = (string) ($resp['body'] ?? '');
    $rheaders = $resp['headers'] ?? [];
    $out = "HTTP/1.1 $status X\r\n";
    $hasType = false;
    foreach ($rheaders as $n => $v) {
        $out .= "$n: $v\r\n";
        $hasType = $hasType || strtolower((string) $n) === 'content-type';
    }
    if (!$hasType && $rbody !== '') {
        $out .= "Content-Type: application/json\r\n";
    }
    if ($action === 'truncate') {
        $out .= "Content-Length: 1000\r\nConnection: close\r\n\r\n" . substr($rbody === '' ? '0123456789abcdef' : $rbody, 0, 10);
    } else {
        $out .= 'Content-Length: ' . strlen($rbody) . "\r\nConnection: close\r\n\r\n" . $rbody;
    }
    fwrite($conn, $out);
    fclose($conn);
}
