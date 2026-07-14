<?php

declare(strict_types=1);

namespace Kilden\Tests\Support;

use Kilden\Transport\Transport;
use Kilden\Transport\TransportResponse;

final class FakeTransport implements Transport
{
    /** @var list<TransportResponse> */
    private $responses;

    /** @var list<array{url: string, body: string, headers: array<string, string>, timeout: float}> */
    public $requests = [];

    /**
     * @param list<TransportResponse> $responses consumed in order; the last
     *        one repeats forever
     */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses !== [] ? $responses : [new TransportResponse(200, '{"status":"ok"}')];
    }

    public function send(string $url, string $body, array $headers, float $timeout): TransportResponse
    {
        $this->requests[] = ['url' => $url, 'body' => $body, 'headers' => $headers, 'timeout' => $timeout];

        return count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
    }

    /**
     * @return array<string, mixed>
     */
    public function lastPayload(): array
    {
        $last = $this->requests[count($this->requests) - 1];
        $body = $last['body'];
        if (isset($last['headers']['Content-Encoding']) && $last['headers']['Content-Encoding'] === 'gzip') {
            $body = (string) gzdecode($body);
        }

        return (array) json_decode($body, true);
    }
}
