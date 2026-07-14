<?php

declare(strict_types=1);

namespace Kilden\Tests\Integration;

use Kilden\Client;
use PHPUnit\Framework\TestCase;

/**
 * Behavior contracts exercised against the real mock server: retries,
 * Retry-After, non-retryable failures, gzip on the wire, flags end to end.
 *
 * @group integration
 */
final class BehaviorTest extends TestCase
{
    /** @var MockServer */
    private $mock;

    protected function setUp(): void
    {
        $this->mock = new MockServer(getenv('KILDEN_MOCK_URL') ?: 'http://127.0.0.1:8091');
        if (!$this->mock->isUp()) {
            self::markTestSkipped('mock server is not running at ' . $this->mock->url());
        }
        $this->mock->reset();
    }

    private function client(): Client
    {
        return new Client('sk_test_secret', [
            'host' => $this->mock->url(),
            'flush_at' => 1000,
            'timeout' => 5,
        ]);
    }

    public function testRetriesOn429HonoringRetryAfter(): void
    {
        $this->mock->fail(['times' => 1, 'status' => 429, 'retry_after' => 1]);

        $client = $this->client();
        $client->track('user_1', 'retried_event');
        $start = microtime(true);
        $client->flush();
        $elapsed = microtime(true) - $start;

        $events = $this->mock->capturedEvents();
        self::assertCount(1, $events);
        self::assertSame('retried_event', $events[0]['event']);
        self::assertGreaterThanOrEqual(1.0, $elapsed, 'must wait the Retry-After second');
        self::assertSame(0, $client->droppedCount());
    }

    public function testDoesNotRetry401(): void
    {
        $this->mock->fail(['times' => 1, 'status' => 401]);

        $client = $this->client();
        $client->track('user_1', 'rejected_event');
        $client->flush();

        self::assertSame([], $this->mock->capturedEvents());
        self::assertSame(1, $client->droppedCount());

        // The armed failure is spent; the client keeps working.
        $client->track('user_1', 'after_failure');
        $client->flush();
        self::assertCount(1, $this->mock->capturedEvents());
    }

    public function testRecoversFromServerErrors(): void
    {
        $this->mock->fail(['times' => 2, 'status' => 500]);

        $client = $this->client();
        $client->track('user_1', 'persistent_event');
        $client->flush();

        $events = $this->mock->capturedEvents();
        self::assertCount(1, $events);
        self::assertSame(0, $client->droppedCount());
    }

    public function testGzipOnTheWire(): void
    {
        $client = $this->client();
        $client->track('user_1', 'big_event', ['blob' => str_repeat('x', 4096)]);
        $client->flush();

        $batches = $this->mock->capturedBatches();
        self::assertCount(1, $batches);
        self::assertTrue((bool) $batches[0]['gzip'], 'payload above 1 KiB must travel gzipped');
        self::assertSame('kilden-php/' . Client::VERSION, $batches[0]['headers']['User-Agent']);
    }

    public function testFlagsEndToEnd(): void
    {
        $this->mock->flags([
            ['key' => 'on_flag', 'active' => true, 'rollout_percentage' => 100],
            ['key' => 'off_flag', 'active' => false, 'rollout_percentage' => 100],
            ['key' => 'variant_flag_1', 'active' => true, 'rollout_percentage' => 100, 'variants' => [
                ['key' => 'control', 'rollout_percentage' => 50],
                ['key' => 'test', 'rollout_percentage' => 50],
            ]],
        ]);

        $client = $this->client();
        self::assertTrue($client->isEnabled('on_flag', 'user_42'));
        self::assertFalse($client->isEnabled('off_flag', 'user_42'));
        self::assertFalse($client->isEnabled('missing_flag', 'user_42'));
        self::assertTrue($client->isEnabled('missing_flag', 'user_42', ['default' => true]));

        $variant = $client->getFeatureFlag('variant_flag_1', 'user_42');
        self::assertIsString($variant);
        self::assertContains($variant, ['control', 'test']);
    }

    public function testFlagTimeoutReturnsDefault(): void
    {
        $this->mock->fail(['times' => 1, 'mode' => 'timeout', 'delay_ms' => 3000]);

        $client = new Client('sk_test_secret', [
            'host' => $this->mock->url(),
            'flush_at' => 1000,
            'timeout' => 1,
        ]);

        $start = microtime(true);
        self::assertTrue($client->isEnabled('any', 'user_1', ['default' => true]));
        self::assertLessThan(2.5, microtime(true) - $start, 'must respect the 1s timeout, not wait the 3s delay');
    }
}
