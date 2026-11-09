<?php

namespace Shopify\Test\Auth;

use PHPUnit\Framework\TestCase;
use Shopify\Auth\ClientAssertionFactory;
use Shopify\Auth\OAuthClient;
use Shopify\Auth\PemAssertionSigner;
use Shopify\Auth\ShopifyAccount;
use Shopify\Auth\TokenProvider;
use Shopify\Auth\TokenProviderFactory;
use Shopify\Auth\TokenSet;
use Shopify\Auth\TokenUnavailableException;
use Shopify\Auth\UnsupportedSourceException;

final class TokenProviderTest extends TestCase
{
    private FakeTransport $http;
    private TestLogger $logger;
    private int $now = 10000;

    protected function setUp(): void
    {
        TokenProvider::resetMemory();
        $this->http = new FakeTransport();
        $this->logger = new TestLogger();
        $this->now = 10000;
    }

    private function provider(?string $strategy = 'expiring', string $kid = 'kid-1'): TokenProvider
    {
        $clock = fn() => $this->now;
        $oauth = new OAuthClient('client-123', null,
            fn() => new ClientAssertionFactory('client-123', $kid, new PemAssertionSigner(Support::pem()), $clock),
            $this->http, $this->logger, $clock, fn(int $s) => null);
        return new TokenProvider($oauth, $strategy, $this->logger, $clock);
    }

    private function account(MemoryCache $cache, string $source = 'shopify_app', ?string $legacy = 'shpat_legacy', ?string $auth = null, string $id = 'sando_1'): ShopifyAccount
    {
        return new ShopifyAccount($id, $source, 'store-a.myshopify.com', $legacy, $auth, $cache);
    }

    public function testInMemoryTokenBeforeRenewAtMeansNoQueryAndNoHttp(): void
    {
        $cache = new MemoryCache();
        $this->http->push(200, Support::tokenJson('shpat_minted'));
        $p = $this->provider();
        $this->assertSame('shpat_minted', $p->getAccessToken($this->account($cache)));
        $gets = $cache->gets;

        $this->now += 60;
        $this->assertSame('shpat_minted', $this->provider()->getAccessToken($this->account($cache)), 'a new provider (next invocation) reuses memory');
        $this->assertSame($gets, $cache->gets, 'no cache query');
        $this->assertCount(1, $this->http->requests, 'no HTTP');
    }

    public function testCachedSettingBeforeRenewAtMeansNoHttp(): void
    {
        $cache = new MemoryCache();
        $cache->token = new TokenSet('shpat_cached', $this->now + 3000, $this->now + 1000);
        $this->assertSame('shpat_cached', $this->provider()->getAccessToken($this->account($cache)));
        $this->assertSame([], $this->http->requests);
    }

    public function testCachedSettingPastRenewAtMintsAndWritesTheSetting(): void
    {
        $cache = new MemoryCache();
        $cache->token = new TokenSet('shpat_old', $this->now + 500, $this->now - 1);
        $this->http->push(200, Support::tokenJson('shpat_new', 3599));
        $this->assertSame('shpat_new', $this->provider()->getAccessToken($this->account($cache)));
        $this->assertSame('shpat_new', $cache->token->token);
        $this->assertSame($this->now + 3599, $cache->token->expiresAt);
        $this->assertTrue($this->logger->has('info', 'shopify.token', ['source' => 'app_only', 'result' => 'ok', 'kid' => 'kid-1']));
    }

    public function testMissingSettingMints(): void
    {
        $cache = new MemoryCache();
        $this->http->push(200, Support::tokenJson('shpat_new'));
        $this->assertSame('shpat_new', $this->provider()->getAccessToken($this->account($cache)));
        $this->assertSame(1, $cache->puts);
    }

    public function testShortLifetimeCapMeansASecondCallDoesNotMintAgain(): void
    {
        $cache = new MemoryCache();
        $this->http->push(200, Support::tokenJson('shpat_short', 600));
        $p = $this->provider();
        $p->getAccessToken($this->account($cache));
        $this->assertSame($this->now + 300, $cache->token->renewAt);
        $this->now += 299;
        $this->assertSame('shpat_short', $p->getAccessToken($this->account($cache)));
        $this->assertCount(1, $this->http->requests);
    }

    public function testKillSwitchOrMissingStrategyReturnsTheLegacyTokenWithoutHttp(): void
    {
        foreach (['legacy', null, '', 'typo'] as $strategy) {
            $cache = new MemoryCache();
            $this->assertSame('shpat_legacy', $this->provider($strategy)->getAccessToken($this->account($cache)), var_export($strategy, true));
            $this->assertSame(0, $cache->gets);
        }
        $this->assertSame([], $this->http->requests);
        $this->assertTrue($this->logger->has('info', 'shopify.token', ['source' => 'legacy', 'reason' => 'kill_switch']));
        $this->assertTrue($this->logger->has('warning', 'shopify.token_strategy_unknown'));
    }

    public function testKillSwitchWithoutALegacyTokenMints(): void
    {
        $this->http->push(200, Support::tokenJson('shpat_new'));
        $this->assertSame('shpat_new', $this->provider('legacy')->getAccessToken($this->account(new MemoryCache(), legacy: null)));
    }

    public function testSignerIsNotBuiltUntilTheFirstMint(): void
    {
        // Broken key config: construction and cache hits work; only the mint fails (and falls back).
        $p = TokenProviderFactory::tokenProvider(['api_key' => 'c', 'token_strategy' => 'expiring', 'jwt_kid' => 'k', 'jwt_private_key' => 'garbage'], $this->logger, $this->http);
        $cache = new MemoryCache();
        $cache->token = new TokenSet('shpat_cached', time() + 3000, time() + 1000);
        $this->assertSame('shpat_cached', $p->getAccessToken($this->account($cache)));

        TokenProvider::resetMemory();
        $this->assertSame('shpat_legacy', $p->getAccessToken($this->account(new MemoryCache())));
        $this->assertTrue($this->logger->has('error', 'shopify.token', ['source' => 'app_only', 'result' => 'failed']));
        $this->assertSame([], $this->http->requests);
    }

    public function testEachInvocationUsesItsOwnKeyWhileMemoryCarriesOver(): void
    {
        $this->http->push(200, Support::tokenJson('shpat_one'));
        $this->provider('expiring', 'kid-old')->getAccessToken($this->account(new MemoryCache(), id: 'a1'));
        // Next invocation: rotated key. a1 is served from memory; a2 mints with the new kid.
        $this->http->push(200, Support::tokenJson('shpat_two'));
        $p2 = $this->provider('expiring', 'kid-new');
        $this->assertSame('shpat_one', $p2->getAccessToken($this->account(new MemoryCache(), id: 'a1')));
        $this->assertSame('shpat_two', $p2->getAccessToken($this->account(new MemoryCache(), id: 'a2')));
        $this->assertCount(2, $this->http->requests);
        $this->assertSame('kid-old', Support::jwtPart($this->http->requests[0]['form']['client_assertion'], 0)['kid']);
        $this->assertSame('kid-new', Support::jwtPart($this->http->requests[1]['form']['client_assertion'], 0)['kid']);
    }

    public function testMint5xxThreeTimesFallsBackToLegacy(): void
    {
        $this->http->push(500)->push(500)->push(500);
        $this->assertSame('shpat_legacy', $this->provider()->getAccessToken($this->account(new MemoryCache())));
        $this->assertCount(3, $this->http->requests);
        $this->assertTrue($this->logger->has('warning', 'shopify.token', ['source' => 'legacy', 'reason' => 'mint_failed']));
    }

    public function testMint400IsNotRetriedAndFallsBack(): void
    {
        $this->http->push(400, '{"error":"app_not_installed"}');
        $this->assertSame('shpat_legacy', $this->provider()->getAccessToken($this->account(new MemoryCache())));
        $this->assertCount(1, $this->http->requests);
    }

    public function testMintFailureWithoutLegacyTokenThrows(): void
    {
        $this->http->push(400, '{"error":"app_not_installed"}');
        $this->expectException(TokenUnavailableException::class);
        $this->provider()->getAccessToken($this->account(new MemoryCache(), legacy: ''));
    }

    public function testCacheReadAndWriteFailuresDoNotBreakMinting(): void
    {
        $cache = new MemoryCache();
        $cache->failReads = true;
        $cache->failWrites = true;
        $this->http->push(200, Support::tokenJson('shpat_new'));
        $this->assertSame('shpat_new', $this->provider()->getAccessToken($this->account($cache)));
        $this->assertTrue($this->logger->has('error', 'shopify.token_cache', ['op' => 'read']));
        $this->assertTrue($this->logger->has('error', 'shopify.token_cache', ['op' => 'write']));
    }

    public function testShopifySourceWithAppMarkerMints(): void
    {
        $this->http->push(200, Support::tokenJson('shpat_new'));
        $this->assertSame('shpat_new', $this->provider()->getAccessToken($this->account(new MemoryCache(), 'shopify', 'shpat_legacy', 'app')));
    }

    public function testShopifySourceWithoutOrWithCustomMarkerUsesStoredTokenAndNoHttp(): void
    {
        foreach ([null, 'custom'] as $marker) {
            $this->assertSame('shpat_custom', $this->provider()->getAccessToken($this->account(new MemoryCache(), 'shopify', 'shpat_custom', $marker)));
        }
        $this->assertSame([], $this->http->requests);
    }

    public function testCustomAppWithoutTokenThrows(): void
    {
        $this->expectException(TokenUnavailableException::class);
        $this->provider()->getAccessToken($this->account(new MemoryCache(), 'shopify', null, 'custom'));
    }

    public function testShopifyFbeThrows(): void
    {
        $this->expectException(UnsupportedSourceException::class);
        $this->provider()->getAccessToken($this->account(new MemoryCache(), 'shopify_fbe'));
    }

    public function testForceRenewClearsCacheAndMints(): void
    {
        $cache = new MemoryCache();
        $cache->token = new TokenSet('shpat_bad', $this->now + 3000, $this->now + 1000);
        $p = $this->provider();
        $this->assertSame('shpat_bad', $p->getAccessToken($this->account($cache)));
        $this->http->push(200, Support::tokenJson('shpat_fresh'));
        $this->assertSame('shpat_fresh', $p->forceRenew($this->account($cache)));
        $this->assertSame(1, $cache->clears);
        $this->assertSame('shpat_fresh', $p->getAccessToken($this->account($cache)));
    }

    public function testLogsNeverContainTokens(): void
    {
        $this->http->push(200, Support::tokenJson('shpat_SECRETMINTED'));
        $p = $this->provider('legacy');
        $p->getAccessToken($this->account(new MemoryCache(), legacy: 'shpat_SECRETLEGACY'));
        $this->provider()->getAccessToken($this->account(new MemoryCache(), legacy: 'shpat_SECRETLEGACY', id: 'other'));
        $this->assertStringNotContainsString('SECRET', $this->logger->allText());
    }
}
