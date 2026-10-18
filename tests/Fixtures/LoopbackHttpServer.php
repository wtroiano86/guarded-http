<?php

declare(strict_types=1);

namespace Wtroiano\GuardedHttp\Tests\Fixtures;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use Wtroiano\GuardedHttp\Contracts\EndpointPolicy;

/** Owns an isolated HTTP server for tests of the real cURL transport. */
final readonly class LoopbackHttpServer
{
    private Process $process;

    private int $port;

    public function __construct()
    {
        $this->process = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__.'/http-server.php']);
        $this->process->setTimeout(15)->start();

        try {
            if (! $this->process->waitUntil(fn (): bool => preg_match('/^READY ([1-9][0-9]*)\r?$/m', $this->process->getOutput()) === 1)) {
                throw new RuntimeException('Loopback server failed: '.$this->process->getErrorOutput());
            }

            preg_match('/^READY ([1-9][0-9]*)\r?$/m', $this->process->getOutput(), $matches);
            $this->port = (int) $matches[1];
        } catch (Throwable $throwable) {
            $this->stop();

            throw $throwable;
        }
    }

    public function url(string $path): string
    {
        return 'http://guarded-http.test:'.$this->port.$path;
    }

    /**
     * Authorize only this fixture's host and port, selecting successive addresses.
     *
     * @param  list<string>  $addresses
     */
    public function policy(array $addresses = ['127.0.0.1']): EndpointPolicy
    {
        return new class($this->port, $addresses) implements EndpointPolicy
        {
            private int $calls = 0;

            /** @param list<string> $addresses */
            public function __construct(private readonly int $port, private readonly array $addresses) {}

            public function resolve(string $url): array
            {
                $parts = parse_url($url);
                throw_if(($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') !== 'guarded-http.test' || ($parts['port'] ?? 0) !== $this->port, RuntimeException::class, 'Unexpected loopback destination.');

                $address = $this->addresses[min($this->calls++, count($this->addresses) - 1)];

                return ['host' => 'guarded-http.test', 'port' => $this->port, 'address' => $address];
            }
        };
    }

    public function stop(): void
    {
        $this->process->stop(0);
    }
}
