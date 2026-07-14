<?php

declare(strict_types=1);

namespace Kilden\Tests\Unit;

use InvalidArgumentException;
use Kilden\Client;
use Kilden\Tests\Support\FakeTransport;
use Kilden\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function client(FakeTransport $transport, array $options = []): Client
    {
        return new Client('sk_test_secret', array_merge(['transport' => $transport, 'flush_at' => 1000], $options));
    }

    public function testRejectsEmptyWriteKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client('');
    }

    public function testRejectsPublicWriteKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/secret/i');
        new Client('wk_something_public');
    }

    public function testRejectsUnknownOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/flushAt/');
        new Client('sk_test_secret', ['flushAt' => 5]);
    }

    public function testDisabledClientIsANoOp(): void
    {
        $client = new Client('sk_test_secret', ['enabled' => false]);
        $client->track('user_1', 'ignored');
        $client->flush();
        $client->close();

        self::assertSame(0, $client->droppedCount());
    }

    public function testTrackBuildsTheWireEvent(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->track('user_1', 'signup', ['plan' => 'pro']);
        $client->flush();

        $payload = $transport->lastPayload();
        self::assertSame('sk_test_secret', $payload['write_key']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $payload['sent_at']);
        self::assertCount(1, $payload['batch']);
        $event = $payload['batch'][0];
        self::assertSame('signup', $event['event']);
        self::assertSame('user_1', $event['distinct_id']);
        self::assertSame(['plan' => 'pro'], $event['properties']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $event['uuid']);
    }

    public function testEmptyPropertiesEncodeAsObject(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->track('user_1', 'bare');
        $client->flush();

        $raw = $transport->requests[0]['body'];
        self::assertStringContainsString('"properties":{}', $raw);
    }

    public function testIdentifyWrapsTraitsInSet(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->identify('user_1', ['plan' => 'pro']);
        $client->flush();

        $event = $transport->lastPayload()['batch'][0];
        self::assertSame('$identify', $event['event']);
        self::assertSame(['$set' => ['plan' => 'pro']], $event['properties']);
    }

    public function testAliasShape(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->alias('anon_previous', 'user_42');
        $client->flush();

        $event = $transport->lastPayload()['batch'][0];
        self::assertSame('$alias', $event['event']);
        self::assertSame('anon_previous', $event['distinct_id']);
        self::assertSame(['$alias' => 'user_42'], $event['properties']);
    }

    /**
     * @dataProvider droppedInputs
     */
    public function testInvalidInputIsDroppedWithoutSending(callable $call): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $call($client);
        $client->flush();

        self::assertSame([], $transport->requests);
    }

    /**
     * @return iterable<string, array{callable(Client): void}>
     */
    public function droppedInputs(): iterable
    {
        yield 'empty distinct_id' => [static function (Client $c): void { $c->track('', 'x'); }];
        yield 'empty event' => [static function (Client $c): void { $c->track('user_1', ''); }];
        yield 'oversize event' => [static function (Client $c): void { $c->track('user_1', str_repeat('x', 201)); }];
        yield 'oversize distinct_id' => [static function (Client $c): void { $c->track(str_repeat('x', 513), 'x'); }];
        yield 'list properties' => [static function (Client $c): void { $c->track('user_1', 'x', ['a', 'b']); }];
        yield 'bad timestamp' => [static function (Client $c): void { $c->track('user_1', 'x', [], ['timestamp' => 'not a time']); }];
        yield 'bad uuid' => [static function (Client $c): void { $c->track('user_1', 'x', [], ['uuid' => 'nope']); }];
        yield 'alias without previous' => [static function (Client $c): void { $c->alias('', 'user_1'); }];
        yield 'alias without target' => [static function (Client $c): void { $c->alias('user_1', ''); }];
    }

    public function testExplicitTimestampAndUuidAreUsed(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->track('user_1', 'backfill', [], [
            'timestamp' => '2026-01-02T03:04:05.678Z',
            'uuid' => '0197fa10-7a2b-7c3d-8e4f-5a6b7c8d9e0f',
        ]);
        $client->flush();

        $event = $transport->lastPayload()['batch'][0];
        self::assertSame('2026-01-02T03:04:05.678Z', $event['timestamp']);
        self::assertSame('0197fa10-7a2b-7c3d-8e4f-5a6b7c8d9e0f', $event['uuid']);
    }

    public function testQueueIsBoundedDroppingTheNewest(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['max_queue_size' => 3, 'flush_at' => 1000]);
        for ($i = 0; $i < 5; ++$i) {
            $client->track('user_1', 'e' . $i);
        }
        self::assertSame(2, $client->droppedCount());
        $client->flush();

        $events = array_map(
            static function (array $e): string { return $e['event']; },
            $transport->lastPayload()['batch']
        );
        self::assertSame(['e0', 'e1', 'e2'], $events);
    }

    public function testFlushAtTriggersDelivery(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['flush_at' => 2]);
        $client->track('user_1', 'first');
        self::assertSame([], $transport->requests);
        $client->track('user_1', 'second');

        self::assertCount(1, $transport->requests);
        self::assertCount(2, $transport->lastPayload()['batch']);
    }

    public function testChunksAtOneThousandEvents(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['flush_at' => 5000, 'max_queue_size' => 5000]);
        for ($i = 0; $i < 1001; ++$i) {
            $client->track('user_1', 'e');
        }
        $client->flush();

        self::assertCount(2, $transport->requests);
    }

    public function testCloseIsIdempotentAndRefusesLaterEvents(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->track('user_1', 'before');
        $client->close();
        $client->close();
        $client->track('user_1', 'after');
        $client->flush();

        self::assertCount(1, $transport->requests);
        self::assertSame('before', $transport->lastPayload()['batch'][0]['event']);
        self::assertSame(1, $client->droppedCount());
    }

    public function testGzipAboveThreshold(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->track('user_1', 'big', ['blob' => str_repeat('x', 2000)]);
        $client->flush();

        $request = $transport->requests[0];
        self::assertSame('gzip', $request['headers']['Content-Encoding']);
        self::assertSame('big', $transport->lastPayload()['batch'][0]['event']);
    }

    public function testNoGzipBelowThreshold(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport);
        $client->track('user_1', 'small');
        $client->flush();

        self::assertArrayNotHasKey('Content-Encoding', $transport->requests[0]['headers']);
    }

    public function testDollarPrefixedEventsAreSentAnyway(): void
    {
        $transport = new FakeTransport();
        $client = $this->client($transport, ['debug' => true]);
        $client->track('user_1', '$custom');
        $client->flush();

        self::assertSame('$custom', $transport->lastPayload()['batch'][0]['event']);
    }

    public function testNonRetryableFailureCountsDropped(): void
    {
        $transport = new FakeTransport([new TransportResponse(401, 'unknown write_key')]);
        $client = $this->client($transport);
        $client->track('user_1', 'e1');
        $client->track('user_1', 'e2');
        $client->flush();

        self::assertCount(1, $transport->requests);
        self::assertSame(2, $client->droppedCount());
    }

    public function testFeatureFlagsFromDecide(): void
    {
        $transport = new FakeTransport([
            new TransportResponse(200, '{"flags":{"on":true,"variant":"blue","off":false},"sessionRecording":{"enabled":false,"sampleRate":0}}'),
        ]);
        $client = $this->client($transport);

        self::assertTrue($client->isEnabled('on', 'user_1'));
        self::assertTrue($client->isEnabled('variant', 'user_1'));
        self::assertFalse($client->isEnabled('off', 'user_1'));
        self::assertSame('blue', $client->getFeatureFlag('variant', 'user_1'));
        self::assertFalse($client->getFeatureFlag('missing', 'user_1'));
        self::assertSame('fallback', $client->getFeatureFlag('missing', 'user_1', ['default' => 'fallback']));

        // All six reads above hit the 30s cache: one request total.
        self::assertCount(1, $transport->requests);
        self::assertStringContainsString('/decide', $transport->requests[0]['url']);
    }

    public function testPersonPropertiesBypassTheCache(): void
    {
        $transport = new FakeTransport([
            new TransportResponse(200, '{"flags":{"f":true}}'),
        ]);
        $client = $this->client($transport);

        $client->isEnabled('f', 'user_1');
        $client->isEnabled('f', 'user_1', ['person_properties' => ['plan' => 'pro']]);
        $client->isEnabled('f', 'user_1');

        self::assertCount(2, $transport->requests);
        $withProps = json_decode($transport->requests[1]['body'], true);
        self::assertSame(['plan' => 'pro'], $withProps['person_properties']);
        $without = json_decode($transport->requests[0]['body'], true);
        self::assertArrayNotHasKey('person_properties', $without);
    }

    public function testFlagFailureReturnsDefault(): void
    {
        $transport = new FakeTransport([new TransportResponse(500, 'boom')]);
        $client = $this->client($transport);

        self::assertFalse($client->isEnabled('f', 'user_1'));
        self::assertTrue($client->isEnabled('f', 'user_1', ['default' => true]));
        // One attempt per call, no retries, failures not cached.
        self::assertCount(2, $transport->requests);
    }
}
