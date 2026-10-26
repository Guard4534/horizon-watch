<?php

namespace Tests\Support;

use RuntimeException;

final class FixtureServer
{
    private const ATTEMPTS = 5;

    private const STARTUP_SECONDS = 10;

    private const SETTLE_MICROSECONDS = 200_000;

    /**
     * @param  resource  $process
     */
    private function __construct(public readonly int $port, private $process) {}

    /**
     * @param  array<string, string>  $environment
     */
    public static function start(string $router, array $environment, int $workers): self
    {
        is_file($router) || throw new RuntimeException("No fixture router at {$router}.");

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $port = self::freePort();

            $process = proc_open(
                [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
                [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
                $pipes,
                null,
                [...getenv(), ...$environment, 'PHP_CLI_SERVER_WORKERS' => (string) $workers],
            );

            if ($process === false) {
                break;
            }

            if (self::listening($process, $port)) {
                return new self($port, $process);
            }

            proc_terminate($process);
            proc_close($process);
        }

        throw new RuntimeException("The fixture server for {$router} did not start.");
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('No free port for a fixture server.');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    /**
     * @param  resource  $process
     */
    private static function listening($process, int $port): bool
    {
        $deadline = microtime(true) + self::STARTUP_SECONDS;

        while (microtime(true) < $deadline) {
            if (! proc_get_status($process)['running']) {
                return false;
            }

            $probe = @fsockopen('127.0.0.1', $port, timeout: 0.1);

            if ($probe !== false) {
                fclose($probe);
                usleep(self::SETTLE_MICROSECONDS);

                return proc_get_status($process)['running'];
            }

            usleep(50_000);
        }

        return false;
    }
}
