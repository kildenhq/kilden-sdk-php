<?php

declare(strict_types=1);

namespace Kilden\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Kilden\FeatureFlags\FlagCache;
use Kilden\Internal\Timestamps;
use Kilden\Internal\Uuid;
use PHPUnit\Framework\TestCase;

final class InternalsTest extends TestCase
{
    public function testUuidV7Shape(): void
    {
        $seen = [];
        for ($i = 0; $i < 500; ++$i) {
            $uuid = Uuid::v7();
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $uuid
            );
            $seen[$uuid] = true;
        }
        self::assertCount(500, $seen, 'uuids must be unique');
    }

    public function testUuidV7EmbedsCurrentTime(): void
    {
        $uuid = Uuid::v7();
        $ms = hexdec(substr(str_replace('-', '', $uuid), 0, 12));
        self::assertEqualsWithDelta(microtime(true) * 1000, $ms, 2000.0);
    }

    public function testNowFormat(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            Timestamps::now()
        );
    }

    public function testNormalizeIsoString(): void
    {
        self::assertSame('2026-01-02T03:04:05.678Z', Timestamps::normalize('2026-01-02T03:04:05.678Z'));
        // Offset forms are converted to UTC, not preserved.
        self::assertSame('2026-01-02T00:04:05.000Z', Timestamps::normalize('2026-01-02T03:04:05+03:00'));
    }

    public function testNormalizeDateTime(): void
    {
        $dt = new DateTimeImmutable('2026-01-02 03:04:05.678901', new DateTimeZone('UTC'));
        self::assertSame('2026-01-02T03:04:05.678Z', Timestamps::normalize($dt));
    }

    /**
     * @dataProvider garbageTimestamps
     * @param mixed $value
     */
    public function testNormalizeRejectsGarbage($value): void
    {
        self::assertNull(Timestamps::normalize($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public function garbageTimestamps(): iterable
    {
        yield 'word' => ['not a time'];
        yield 'empty' => [''];
        yield 'int' => [1730000000];
        yield 'null' => [null];
        yield 'array' => [[]];
    }

    public function testFlagCacheTtl(): void
    {
        $cache = new FlagCache();
        $cache->put('u1', ['f' => true], 1000.0);

        self::assertSame(['f' => true], $cache->get('u1', 1000.0 + FlagCache::TTL_SECONDS - 1));
        self::assertNull($cache->get('u1', 1000.0 + FlagCache::TTL_SECONDS));
    }

    public function testFlagCacheEvictsLeastRecentlyUsed(): void
    {
        $cache = new FlagCache();
        for ($i = 0; $i < FlagCache::MAX_ENTRIES; ++$i) {
            $cache->put('u' . $i, ['i' => $i], 1000.0);
        }
        // Touch u0 so u1 becomes the coldest entry.
        self::assertNotNull($cache->get('u0', 1001.0));
        $cache->put('overflow', ['i' => -1], 1001.0);

        self::assertNotNull($cache->get('u0', 1002.0));
        self::assertNull($cache->get('u1', 1002.0));
        self::assertNotNull($cache->get('overflow', 1002.0));
    }
}
