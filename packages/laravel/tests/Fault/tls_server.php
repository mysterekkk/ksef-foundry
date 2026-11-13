<?php

/**
 * Real TLS server on 127.0.0.1 with a SELF-SIGNED certificate (never trusted): proves that clients refuse it.
 * Usage: php tls_server.php <portFile> <pemFile(cert+key)> <logFile>
 * Logs "accepted" for every TCP connection that reached it. Stops after 20 seconds.
 */

declare(strict_types=1);

[, $portFile, $pemFile, $logFile] = $argv;
$ctx = stream_context_create(['ssl' => ['local_cert' => $pemFile, 'verify_peer' => false, 'allow_self_signed' => true]]);
$server = stream_socket_server('ssl://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
file_put_contents($portFile, (string) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1));
$deadline = time() + 20;
while (time() < $deadline) {
    $conn = @stream_socket_accept($server, 1);
    if ($conn === false) {
        continue;
    }
    file_put_contents($logFile, "accepted\n", FILE_APPEND);
    // a client that (wrongly) trusted us would now send a request; answer a valid one so a leak is detectable
    stream_set_timeout($conn, 2);
    $req = (string) fread($conn, 4096);
    if ($req !== '') {
        file_put_contents($logFile, "REQUEST-RECEIVED\n", FILE_APPEND);
        fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    }
    fclose($conn);
}
