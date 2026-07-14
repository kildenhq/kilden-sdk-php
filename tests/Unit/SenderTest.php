<?php

declare(strict_types=1);

namespace Kilden\Tests\Unit;

use Kilden\Internal\Sender;
use Kilden\Tests\Support\FakeTransport;
use Kilden\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class SenderTest extends TestCase
{
    /** @var list<float> */
    private $sleeps = [];

    private function sender(FakeTransport $transport): Sender
    {
        $this->sleeps = [];

        return new Sender(
            $transport,
            'http://mock.test',
            'sk_test_secret',
            3.0,
            function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
            static function (string $message): void {
                // silent in tests
            }
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(int $count = 1): array
    {
        $events = [];
        for ($i = 0; $i < $count; ++$i) {
            $events[] = [
                'uuid' => '0197fa10-7a2b-7c3d-8e4f-5a6b7c8d9e0f',
                'event' => 'e' . $i,
                'distinct_id' => 'u1',
                'properties' => new \stdClass(),
                'timestamp' => '2026-07-14T12:00:00.000Z',
            ];
        }

        return $events;
    }

    public function testSuccessFirstTry(): void
    {
        $transport = new FakeTransport();
        self::assertTrue($this->sender($transport)->sendBatch($this->events()));
        self::assertCount(1, $transport->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testRetriesOn5xxWithExponentialBackoff(): void
    {
        $transport = new FakeTransport([
            new TransportResponse(500, 'boom'),
            new TransportResponse(502, 'boom'),
            new TransportResponse(200, '{"status":"ok"}'),
        ]);
        self::assertTrue($this->sender($transport)->sendBatch($this->events()));
        self::assertCount(3, $transport->requests);
        self::assertCount(2, $this->sleeps);
        // Backoff n is 0.5 * 2^(n-1) jittered by [0.5, 1.5].
        self::assertGreaterThanOrEqual(0.25, $this->sleeps[0]);
        self::assertLessThanOrEqual(0.75, $this->sleeps[0]);
        self::assertGreaterThanOrEqual(0.5, $this->sleeps[1]);
        self::assertLessThanOrEqual(1.5, $this->sleeps[1]);
    }

    public function testRetryAfterReplacesBackoffWithoutJitter(): void
    {
        $transport = new FakeTransport([
            new TransportResponse(429, 'slow down', ['Retry-After' => '7']),
            new TransportResponse(200, '{"status":"ok"}'),
        ]);
        self::assertTrue($this->sender($transport)->sendBatch($this->events()));
        self::assertSame([7.0], $this->sleeps);
    }

    public function testGivesUpAfterThreeRetries(): void
    {
        $transport = new FakeTransport([new TransportResponse(500, 'boom')]);
        self::assertFalse($this->sender($transport)->sendBatch($this->events()));
        self::assertCount(4, $transport->requests); // 1 attempt + 3 retries
        self::assertCount(3, $this->sleeps);
    }

    public function testDoesNotRetryOther4xx(): void
    {
        foreach ([400, 401, 403, 413] as $status) {
            $transport = new FakeTransport([new TransportResponse($status, 'nope')]);
            self::assertFalse($this->sender($transport)->sendBatch($this->events()), "status {$status}");
            self::assertCount(1, $transport->requests, "status {$status}");
        }
    }

    public function testRetriesNetworkErrors(): void
    {
        $transport = new FakeTransport([
            TransportResponse::failure('connection refused'),
            new TransportResponse(200, '{"status":"ok"}'),
        ]);
        self::assertTrue($this->sender($transport)->sendBatch($this->events()));
        self::assertCount(2, $transport->requests);
    }

    public function testCorruptBodyOn2xxIsSuccess(): void
    {
        // SPEC §4.3: any 2xx is success — the body is never parsed, so a
        // garbage body must not trigger a retry.
        $transport = new FakeTransport([
            new TransportResponse(200, '{"status": <<< garbage'),
        ]);
        self::assertTrue($this->sender($transport)->sendBatch($this->events()));
        self::assertCount(1, $transport->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testDeadlineShortCircuitsRetries(): void
    {
        $transport = new FakeTransport([new TransportResponse(500, 'boom')]);
        $sender = $this->sender($transport);

        // Deadline already unreachable for any backoff wait.
        self::assertFalse($sender->sendBatch($this->events(), microtime(true) + 0.01));
        self::assertCount(1, $transport->requests);
        self::assertSame([], $this->sleeps);
    }
}
