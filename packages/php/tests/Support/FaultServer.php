<?php

declare(strict_types=1);

namespace LuroWeb\KsefFoundry\Tests\Support;

use LuroWeb\KsefFoundry\Environment;

/**
 * Starts tests/Fault/raw_server.php (a real TCP/HTTP server on 127.0.0.1) as a child process.
 * The SDK under test talks real HTTP to it; nothing is mocked.
 */
final class FaultServer
{
    /** @var resource|null */
    private $process;
    private string $dir;
    private string $logFile;
    public readonly int $port;

    /**
     * @param array<string, list<array<string, mixed>>> $script "METHOD /path" => responses
     */
    public function __construct(array $script)
    {
        $dir = sys_get_temp_dir() . '/ksef-fault-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create temp dir.');
        }
        $this->dir = $dir;
        $this->logFile = $dir . '/requests.log';
        file_put_contents($this->logFile, '');
        file_put_contents($dir . '/script.json', json_encode($script, JSON_THROW_ON_ERROR));
        $cmd = [PHP_BINARY, __DIR__ . '/../Fault/raw_server.php', $dir . '/port', $dir . '/script.json', $this->logFile];
        $pipes = [];
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $dir . '/out.txt', 'w'], 2 => ['file', $dir . '/err.txt', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot start fault server.');
        }
        $this->process = $proc;
        $deadline = microtime(true) + 10;
        while (!is_file($dir . '/port') || trim((string) file_get_contents($dir . '/port')) === '') {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('Fault server did not start: ' . (string) file_get_contents($dir . '/err.txt'));
            }
            usleep(20_000);
        }
        $this->port = (int) trim((string) file_get_contents($dir . '/port'));
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function environment(): Environment
    {
        return Environment::custom($this->baseUrl() . '/v2');
    }

    /** @return list<array<string, mixed>> */
    public function requests(): array
    {
        $out = [];
        foreach (explode("\n", trim((string) file_get_contents($this->logFile))) as $line) {
            if ($line !== '') {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
        }

        return $out;
    }

    public function count(string $method, string $path): int
    {
        return count(array_filter(
            $this->requests(),
            static fn (array $r): bool => $r['method'] === $method && $r['path'] === $path
        ));
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            if (proc_get_status($this->process)['running']) {
                $s = fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.5);
                if (is_resource($s)) {
                    fwrite($s, "GET /__shutdown HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
                    fclose($s);
                }
                usleep(100_000);
            }
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process);
            }
            proc_close($this->process);
            $this->process = null;
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
