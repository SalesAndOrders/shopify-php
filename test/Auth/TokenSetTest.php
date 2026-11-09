<?php

namespace Shopify\Test\Auth;

use PHPUnit\Framework\TestCase;
use Shopify\Auth\TokenSet;

final class TokenSetTest extends TestCase
{
    public function testRenewsSixteenMinutesPlusJitterBeforeExpiry(): void
    {
        $t = TokenSet::fromLifetime('shpat_x', 1000, 3599, 'read_products', 120);
        $this->assertSame(4599, $t->expiresAt);
        $this->assertSame(4599 - 960 - 120, $t->renewAt);
        $this->assertSame('read_products', $t->scope);
    }

    public function testShortLifetimeIsCappedAtHalf(): void
    {
        // expires_in = 600 -> renew_at = t0 + 300 (the cap works)
        $t = TokenSet::fromLifetime('shpat_x', 1000, 600, '', 300);
        $this->assertSame(1300, $t->renewAt);
    }

    public function testRandomJitterStaysInRange(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $t = TokenSet::fromLifetime('shpat_x', 0, 3600);
            $this->assertGreaterThanOrEqual(3600 - 960 - 300, $t->renewAt);
            $this->assertLessThanOrEqual(3600 - 960, $t->renewAt);
        }
    }

    public function testFreshness(): void
    {
        $t = new TokenSet('shpat_x', 2000, 1500);
        $this->assertTrue($t->isFresh(1499));
        $this->assertFalse($t->isFresh(1500));
    }

    public function testJsonRoundTrip(): void
    {
        $t = new TokenSet('shpat_abc', 2000, 1500, 'read_products');
        $back = TokenSet::fromJson($t->toJson());
        $this->assertEquals($t, $back);
        $this->assertSame(['token', 'expires_at', 'renew_at', 'scope'], array_keys(json_decode($t->toJson(), true)));
    }

    public function testFromJsonRejectsGarbage(): void
    {
        foreach ([null, '', 'not json', '{}', '{"token":"","expires_at":1,"renew_at":1}', '{"token":"a","expires_at":"1","renew_at":1}', '[]'] as $bad) {
            $this->assertNull(TokenSet::fromJson($bad), (string) $bad);
        }
    }

    public function testRejectsEmptyToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TokenSet('', 1, 1);
    }

    public function testRejectsNonPositiveLifetime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TokenSet::fromLifetime('shpat_x', 0, 0);
    }

    public function testDebugOutputRedactsToken(): void
    {
        $t = new TokenSet('shpat_secretvalue', 2000, 1500);
        $this->assertStringNotContainsString('secretvalue', print_r($t, true));
        ob_start();
        var_dump($t);
        $this->assertStringNotContainsString('secretvalue', (string) ob_get_clean());
    }
}
