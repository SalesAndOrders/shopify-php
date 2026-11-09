<?php

namespace Shopify\Test\Auth;

use PHPUnit\Framework\TestCase;
use Shopify\Auth\AuthenticatedCall;
use Shopify\Auth\ClientAssertionFactory;
use Shopify\Auth\OAuthClient;
use Shopify\Auth\PemAssertionSigner;
use Shopify\Auth\ShopifyAccount;
use Shopify\Auth\TokenProvider;
use Shopify\Auth\TokenSet;

final class AuthenticatedCallTest extends TestCase
{
    private FakeTransport $http;
    private TestLogger $logger;
    private MemoryCache $cache;
    /** @var list<string> tokens the API call received */
    private array $used = [];

    protected function setUp(): void
    {
        TokenProvider::resetMemory();
        $this->http = new FakeTransport();
        $this->logger = new TestLogger();
        $this->cache = new MemoryCache();
        $this->cache->token = new TokenSet('shpat_cached', time() + 3000, time() + 1000);
        $this->used = [];
    }

    private function runner(): AuthenticatedCall
    {
        $oauth = new OAuthClient('c', null, new ClientAssertionFactory('c', 'k', new PemAssertionSigner(Support::pem())),
            $this->http, $this->logger, null, fn(int $s) => null);
        return new AuthenticatedCall(new TokenProvider($oauth, 'expiring', $this->logger), null, $this->logger);
    }

    private function account(?string $legacy = 'shpat_legacy'): ShopifyAccount
    {
        return new ShopifyAccount('sando_1', 'shopify_app', 's.myshopify.com', $legacy, null, $this->cache);
    }

    /** An API call that answers with the given statuses in turn. */
    private function api(int ...$statuses): \Closure
    {
        return function (string $token) use (&$statuses) {
            $this->used[] = $token;
            return new FakeApiResponse(array_shift($statuses));
        };
    }

    public function testSuccessUsesTheCachedToken(): void
    {
        $r = $this->runner()->run($this->account(), $this->api(200));
        $this->assertSame(200, $r->httpStatus());
        $this->assertSame(['shpat_cached'], $this->used);
    }

    public function testApi401ClearsCacheForcesAMintAndRetriesOnce(): void
    {
        $this->http->push(200, Support::tokenJson('shpat_fresh'));
        $r = $this->runner()->run($this->account(), $this->api(401, 200));
        $this->assertSame(200, $r->httpStatus());
        $this->assertSame(['shpat_cached', 'shpat_fresh'], $this->used);
        $this->assertSame(1, $this->cache->clears);
        $this->assertSame('shpat_fresh', $this->cache->token->token);
    }

    public function testSecond401FallsBackToTheLegacyToken(): void
    {
        $this->http->push(200, Support::tokenJson('shpat_fresh'));
        $r = $this->runner()->run($this->account(), $this->api(401, 401, 200));
        $this->assertSame(200, $r->httpStatus());
        $this->assertSame(['shpat_cached', 'shpat_fresh', 'shpat_legacy'], $this->used);
        $this->assertTrue($this->logger->has('warning', 'shopify.token', ['source' => 'legacy', 'reason' => 'minted_token_rejected']));
    }

    public function testSecond401WithoutLegacyTokenReturnsThe401(): void
    {
        $this->http->push(200, Support::tokenJson('shpat_fresh'));
        $r = $this->runner()->run($this->account(null), $this->api(401, 401));
        $this->assertSame(401, $r->httpStatus());
        $this->assertCount(2, $this->used);
    }

    public function testA401OnTheLegacyTokenIsNotRetried(): void
    {
        $this->cache->token = null;
        $this->http->push(500)->push(500)->push(500); // mint fails -> legacy
        $r = $this->runner()->run($this->account(), $this->api(401));
        $this->assertSame(401, $r->httpStatus());
        $this->assertSame(['shpat_legacy'], $this->used);
    }

    public function test402IsReturnedAndTheTokenKept(): void
    {
        $r = $this->runner()->run($this->account(), $this->api(402));
        $this->assertSame(402, $r->httpStatus());
        $this->assertSame(['shpat_cached'], $this->used);
        $this->assertSame(0, $this->cache->clears);
        $this->assertSame([], $this->http->requests);
    }

    public function test403NonExpiringPhraseLogsCritical(): void
    {
        $call = function (string $token) {
            $this->used[] = $token;
            return new FakeApiResponse(403, '{"errors":"[API] Non-expiring access tokens are no longer accepted for the Admin API."}');
        };
        $this->runner()->run($this->account(), $call);
        $this->assertTrue($this->logger->has('critical', 'shopify.non_expiring_rejected', ['account' => 'sando_1']));
    }

    public function testThrown401IsHandledAndOtherExceptionsAreRethrown(): void
    {
        $this->http->push(200, Support::tokenJson('shpat_fresh'));
        $n = 0;
        $call = function (string $token) use (&$n) {
            $this->used[] = $token;
            if ($n++ === 0) {
                throw new FakeApiException(new FakeApiResponse(401));
            }
            throw new \DomainException('boom');
        };
        try {
            $this->runner()->run($this->account(), $call);
            $this->fail('expected an exception');
        } catch (\DomainException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(['shpat_cached', 'shpat_fresh'], $this->used);
    }
}
