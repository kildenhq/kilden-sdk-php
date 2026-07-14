<?php

declare(strict_types=1);

namespace Kilden\Tests\Unit;

use Kilden\FeatureFlags\Rollout;
use PHPUnit\Framework\TestCase;

final class RolloutTest extends TestCase
{
    public function testMatchesEveryRolloutVector(): void
    {
        $doc = $this->vectors();
        $checked = 0;
        foreach ($doc['rollout'] as $vector) {
            $bucket = Rollout::bucket((string) $vector['flag_key'], (string) $vector['distinct_id']);
            self::assertSame(
                (int) $vector['bucket_floor'],
                (int) $bucket,
                sprintf('bucket(%s, %s)', $vector['flag_key'], $vector['distinct_id'])
            );
            // The float repr in the vector is Go's shortest round-trip form;
            // parsing it back must give the exact same IEEE 754 double.
            self::assertSame((float) $vector['bucket'], $bucket);
            ++$checked;
        }
        self::assertGreaterThanOrEqual(200, $checked);
    }

    public function testMatchesEveryVariantVector(): void
    {
        $doc = $this->vectors();
        foreach ($doc['variants'] as $vector) {
            /** @var list<array{key: string, rollout_percentage: int}> $variants */
            $variants = $vector['variants'];
            self::assertSame(
                $vector['expected'],
                Rollout::variantFor((string) $vector['flag_key'], (string) $vector['distinct_id'], $variants),
                sprintf('variant(%s, %s)', $vector['flag_key'], $vector['distinct_id'])
            );
        }
    }

    /**
     * @return array{rollout: list<array<string, mixed>>, variants: list<array<string, mixed>>}
     */
    private function vectors(): array
    {
        $path = (getenv('KILDEN_SPEC_DIR') ?: '../kilden-sdk-spec') . '/vectors/flag-hashing.json';
        if (!is_file($path)) {
            self::markTestSkipped('spec vectors not found at ' . $path);
        }

        /** @var array{rollout: list<array<string, mixed>>, variants: list<array<string, mixed>>} */
        return json_decode((string) file_get_contents($path), true);
    }
}
