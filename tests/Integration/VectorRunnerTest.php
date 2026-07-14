<?php

declare(strict_types=1);

namespace Kilden\Tests\Integration;

use Kilden\Client;
use PHPUnit\Framework\TestCase;

/**
 * The spec's payload vector runner (SPEC.md §9): replay every call from
 * vectors/payload.json against the live mock server and compare what it
 * captured. This suite is what keeps the five SDKs from drifting apart.
 *
 * @group integration
 */
final class VectorRunnerTest extends TestCase
{
    private const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const ISO_MS = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';

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

    /**
     * @dataProvider payloadVectors
     * @param array<string, mixed> $vector
     */
    public function testPayloadVector(array $vector): void
    {
        $client = $this->client();
        $this->replay($client, (array) $vector['call']);
        $client->flush();

        $events = $this->mock->capturedEvents();

        if (isset($vector['expect']) && $vector['expect'] === 'discarded') {
            self::assertSame([], $events, $vector['name'] . ': event must be discarded client-side');

            return;
        }

        self::assertCount(1, $events, (string) $vector['name']);
        $event = $events[0];
        /** @var array<string, mixed> $expected */
        $expected = $vector['expect_event'];

        self::assertSame($expected['event'], $event['event']);
        self::assertSame($expected['distinct_id'], $event['distinct_id']);
        $this->assertPlaceholder((string) $expected['uuid'], (string) $event['uuid'], self::UUID_V7);
        $this->assertPlaceholder((string) $expected['timestamp'], (string) $event['timestamp'], self::ISO_MS);

        // Deep structural equality, {} and [] distinguished (non-assoc mode).
        self::assertEquals(
            json_decode((string) json_encode($expected['properties'])),
            json_decode((string) json_encode($event['properties'])),
            $vector['name'] . ': properties'
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public function payloadVectors(): iterable
    {
        $path = (getenv('KILDEN_SPEC_DIR') ?: '../kilden-sdk-spec') . '/vectors/payload.json';
        if (!is_file($path)) {
            return;
        }
        /** @var array{vectors: list<array<string, mixed>>} $doc */
        $doc = json_decode((string) file_get_contents($path), true);
        foreach ($doc['vectors'] as $vector) {
            yield (string) $vector['name'] => [$vector];
        }
    }

    /**
     * @param array<string, mixed> $call
     */
    private function replay(Client $client, array $call): void
    {
        /** @var array<string, mixed> $args */
        $args = (array) $call['args'];
        // Decode via non-assoc JSON so nested {} survive as stdClass and
        // list values as arrays — PHP's [] is otherwise ambiguous.
        /** @var \stdClass $rich */
        $rich = json_decode((string) json_encode($call['args']));

        $opts = [];
        if (isset($rich->opts)) {
            $opts = (array) $rich->opts;
        }

        switch ($call['method']) {
            case 'track':
                $client->track(
                    (string) $args['distinct_id'],
                    (string) $args['event'],
                    isset($rich->properties) ? (array) $rich->properties : [],
                    $opts
                );

                return;
            case 'identify':
                $client->identify(
                    (string) $args['distinct_id'],
                    isset($rich->traits) ? (array) $rich->traits : [],
                    $opts
                );

                return;
            case 'alias':
                $client->alias((string) $args['previous_id'], (string) $args['distinct_id']);

                return;
            default:
                self::fail('unknown vector method ' . (string) $call['method']);
        }
    }

    private function assertPlaceholder(string $expected, string $actual, string $regex): void
    {
        if ($expected === '<uuid_v7>' || $expected === '<iso8601_utc_ms>') {
            self::assertMatchesRegularExpression($regex, $actual);

            return;
        }
        self::assertSame($expected, $actual);
    }
}
