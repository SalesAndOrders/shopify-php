<?php

namespace Shopify\Test\Auth;

use PHPUnit\Framework\TestCase;
use Shopify\Auth\ClientAssertionFactory;
use Shopify\Auth\PemAssertionSigner;

final class ClientAssertionFactoryTest extends TestCase
{
    public function testBuildsAVerifiableJwtWithShopifysClaims(): void
    {
        $f = new ClientAssertionFactory('client-123', 'kid-1', new PemAssertionSigner(Support::pem()), fn() => 1700000000);
        $jwt = $f->create('store-a.myshopify.com');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $jwt, 'base64url without padding');
        $this->assertSame(['alg' => 'RS256', 'kid' => 'kid-1'], Support::jwtPart($jwt, 0));
        $claims = Support::jwtPart($jwt, 1);
        $this->assertSame('client-123', $claims['iss']);
        $this->assertSame('client-123', $claims['sub']);
        $this->assertSame('https://store-a.myshopify.com/admin/oauth/access_token', $claims['aud']);
        $this->assertSame(1700000000, $claims['iat']);
        $this->assertSame(1700000060, $claims['exp']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $claims['jti']);

        [$h, $c, $s] = explode('.', $jwt);
        $pub = openssl_pkey_get_public(openssl_pkey_get_details(openssl_pkey_get_private(Support::pem()))['key']);
        $sig = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
        $this->assertSame(1, openssl_verify("$h.$c", $sig, $pub, OPENSSL_ALGO_SHA256));
    }

    public function testEveryAssertionHasANewJti(): void
    {
        $f = new ClientAssertionFactory('c', 'k', new PemAssertionSigner(Support::pem()));
        $jtis = [];
        for ($i = 0; $i < 20; $i++) {
            $jtis[] = Support::jwtPart($f->create('s.myshopify.com'), 1)['jti'];
        }
        $this->assertCount(20, array_unique($jtis));
    }

    public function testSignerRejectsABrokenKeyWithoutEchoingIt(): void
    {
        try {
            new PemAssertionSigner("-----BEGIN PRIVATE KEY-----\nTOPSECRETGARBAGE\n-----END PRIVATE KEY-----\n");
            $this->fail('expected an exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString('TOPSECRET', $e->getMessage());
        }
    }

    public function testSignerAcceptsAPemWithLiteralBackslashN(): void
    {
        $escaped = str_replace("\n", '\n', Support::pem());
        $this->assertNotSame('', (new PemAssertionSigner($escaped))->sign('x'));
    }

    public function testSignerDebugOutputHidesKey(): void
    {
        $this->assertStringNotContainsString('OpenSSL', print_r(new PemAssertionSigner(Support::pem()), true));
    }
}
