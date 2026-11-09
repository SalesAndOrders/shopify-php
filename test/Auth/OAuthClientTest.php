<?php

namespace Shopify\Test\Auth;

use PHPUnit\Framework\TestCase;
use Shopify\Auth\ClientAssertionFactory;
use Shopify\Auth\OAuthClient;
use Shopify\Auth\PemAssertionSigner;
use Shopify\Auth\TokenRequestException;

final class OAuthClientTest extends TestCase
{
    private FakeTransport $http;
    private TestLogger $logger;
    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->http = new FakeTransport();
        $this->logger = new TestLogger();
        $this->slept = [];
    }

    private function client(?string $secret = null, ClientAssertionFactory|\Closure|null $assertions = null): OAuthClient
    {
        $assertions ??= new ClientAssertionFactory('client-123', 'kid-1', new PemAssertionSigner(Support::pem()));
        return new OAuthClient('client-123', $secret, $assertions, $this->http, $this->logger, fn() => 1000, function (int $s) {
            $this->slept[] = $s;
        });
    }

    public function testClientCredentialsSendsExactlyShopifysParameters(): void
    {
        $this->http->push(200, Support::tokenJson('shpat_new', 3599));
        $t = $this->client()->clientCredentials('Store-A.myshopify.com');

        $this->assertSame('shpat_new', $t->token);
        $this->assertSame(1000 + 3599, $t->expiresAt);
        $this->assertSame('read_products', $t->scope);
        $req = $this->http->requests[0];
        $this->assertSame('https://store-a.myshopify.com/admin/oauth/access_token', $req['url']);
        $this->assertSame(['grant_type', 'client_id', 'client_assertion_type', 'client_assertion'], array_keys($req['form']));
        $this->assertSame('client_credentials', $req['form']['grant_type']);
        $this->assertSame('client-123', $req['form']['client_id']);
        $this->assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $req['form']['client_assertion_type']);
        $this->assertSame('https://store-a.myshopify.com/admin/oauth/access_token', Support::jwtPart($req['form']['client_assertion'], 1)['aud']);
        $this->assertContains('Accept: application/json', $req['headers']);
    }

    public function test429HonorsRetryAfterWithANewJtiEveryAttempt(): void
    {
        $this->http->push(429, '', ['retry-after' => '4'])->push(429, '')->push(200, Support::tokenJson('shpat_ok'));
        $this->assertSame('shpat_ok', $this->client()->clientCredentials('s.myshopify.com')->token);
        $this->assertSame([4, 2], $this->slept, 'Retry-After, then the 2 s default');
        $jtis = array_map(fn($r) => Support::jwtPart($r['form']['client_assertion'], 1)['jti'], $this->http->requests);
        $this->assertCount(3, array_unique($jtis));
        $this->assertTrue($this->logger->has('warning', 'shopify.token_429'));
    }

    public function testAtMostThreeAttemptsOn5xx(): void
    {
        $this->http->push(503)->push(502)->push(500);
        try {
            $this->client()->clientCredentials('s.myshopify.com');
            $this->fail('expected an exception');
        } catch (TokenRequestException $e) {
            $this->assertSame(500, $e->status);
            $this->assertTrue($e->retryable);
        }
        $this->assertCount(3, $this->http->requests);
        $this->assertSame([1, 2], $this->slept);
    }

    public function testNetworkErrorIsRetried(): void
    {
        $this->http->push(0)->push(200, Support::tokenJson('shpat_ok'));
        $this->assertSame('shpat_ok', $this->client()->clientCredentials('s.myshopify.com')->token);
        $this->assertSame([1], $this->slept);
    }

    public function testOther4xxIsNotRetried(): void
    {
        $this->http->push(400, '{"error":"app_not_installed","error_description":"The application is not installed on this shop."}');
        try {
            $this->client()->clientCredentials('s.myshopify.com');
            $this->fail('expected an exception');
        } catch (TokenRequestException $e) {
            $this->assertSame('app_not_installed', $e->errorCode);
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('not installed', $e->getMessage());
        }
        $this->assertCount(1, $this->http->requests);
        $this->assertSame([], $this->slept);
    }

    public function testErrorMessageNeverContainsTheAssertion(): void
    {
        $this->http->push(401, '{"error":"invalid_client","error_description":"Invalid client assertion"}');
        try {
            $this->client()->clientCredentials('s.myshopify.com');
        } catch (TokenRequestException $e) {
            $this->assertStringNotContainsString($this->http->requests[0]['form']['client_assertion'], $e->getMessage());
            return;
        }
        $this->fail('expected an exception');
    }

    public function testResponseWithoutExpiresInIsRejected(): void
    {
        $this->http->push(200, '{"access_token":"shpat_x","scope":"a"}');
        $this->expectException(TokenRequestException::class);
        $this->client()->clientCredentials('s.myshopify.com');
    }

    public function testBrokenKeyFailsOnlyTheMintAndNeverConstruction(): void
    {
        $client = $this->client(null, fn() => new ClientAssertionFactory('c', 'k', new PemAssertionSigner('not a key')));
        $this->assertNull($client->kid());
        try {
            $client->clientCredentials('s.myshopify.com');
            $this->fail('expected an exception');
        } catch (TokenRequestException $e) {
            $this->assertSame('assertion_unavailable', $e->errorCode);
            $this->assertFalse($e->retryable);
        }
        $this->assertSame([], $this->http->requests);
    }

    public function testRejectsANonMyshopifyShop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->clientCredentials('evil.example.com/x?');
    }

    public function testExchangeCodeSendsExpiringAndNoAssertion(): void
    {
        $this->http->push(200, json_encode(['access_token' => 'shpat_inst', 'scope' => 'read_products', 'expires_in' => 3600, 'refresh_token' => 'shprt_x', 'refresh_token_expires_in' => 7776000]));
        $t = $this->client('secret-1')->exchangeCode('s.myshopify.com', 'code-9');
        $this->assertSame('shpat_inst', $t->token);
        $this->assertSame('read_products', $t->scope);
        $this->assertSame(['client_id' => 'client-123', 'client_secret' => 'secret-1', 'code' => 'code-9', 'expiring' => '1'], $this->http->requests[0]['form']);
    }

    public function testExchangeCodeIsNeverRetried(): void
    {
        $this->http->push(503);
        $this->expectException(TokenRequestException::class);
        try {
            $this->client('secret-1')->exchangeCode('s.myshopify.com', 'code-9');
        } finally {
            $this->assertCount(1, $this->http->requests);
        }
    }

    public function testExchangeCodeWithoutExpiringReturnsANonExpiringToken(): void
    {
        $this->http->push(200, '{"access_token":"shpat_old","scope":"a"}');
        $t = $this->client('secret-1')->exchangeCode('s.myshopify.com', 'c', false);
        $this->assertSame(PHP_INT_MAX, $t->expiresAt);
        $this->assertArrayNotHasKey('expiring', $this->http->requests[0]['form']);
    }
}
