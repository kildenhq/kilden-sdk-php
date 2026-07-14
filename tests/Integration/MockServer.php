<?php

declare(strict_types=1);

namespace Kilden\Tests\Integration;

/**
 * Thin driver for the kilden-sdk-spec mock capture server's control API.
 */
final class MockServer
{
    /** @var string */
    private $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function url(): string
    {
        return $this->baseUrl;
    }

    public function isUp(): bool
    {
        $response = @file_get_contents($this->baseUrl . '/healthz', false, stream_context_create([
            'http' => ['timeout' => 1, 'ignore_errors' => true],
        ]));

        return $response === 'ok';
    }

    public function reset(): void
    {
        $this->post('/__mock/reset', '{}');
    }

    /**
     * @param array<string, mixed> $body
     */
    public function fail(array $body): void
    {
        $this->post('/__mock/fail', (string) json_encode($body));
    }

    /**
     * @param list<array<string, mixed>> $flags
     */
    public function flags(array $flags): void
    {
        $this->post('/__mock/flags', (string) json_encode(['flags' => $flags]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function capturedEvents(): array
    {
        $raw = (string) file_get_contents($this->baseUrl . '/__mock/captured');
        /** @var array{events: list<array<string, mixed>>} $decoded */
        $decoded = json_decode($raw, true);

        return $decoded['events'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function capturedBatches(): array
    {
        $raw = (string) file_get_contents($this->baseUrl . '/__mock/captured');
        /** @var array{batches: list<array<string, mixed>>|null} $decoded */
        $decoded = json_decode($raw, true);

        return $decoded['batches'] ?? [];
    }

    private function post(string $path, string $body): void
    {
        file_get_contents($this->baseUrl . $path, false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'ignore_errors' => true,
            ],
        ]));
    }
}
