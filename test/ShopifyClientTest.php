<?php
/**
 * @copyright Copyright (c) 2017 Shopify Inc.
 * @license MIT
 */

namespace Shopify;

class ShopifyClientTest extends \PHPUnit\Framework\TestCase
{
    public function testSetShopnameTooManyPeriods()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShopifyClient("abc", "too.many.periods.myshopify.com");
    }

    public function testSetShopnameWithInvalidCharacters()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShopifyClient("abc", "to*&^%$'abc.myshopify.com");
    }

    public function testSetShopnameTooShort()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShopifyClient("abc", "ab.myshopify.com");
    }

    public function testSetShopnameTooLong()
    {
        $this->expectException(\InvalidArgumentException::class);
        $longString = "1234567890abcdefghijklmnopqrstuvwxyz";
        $shopName = $longString . $longString . $longString;
        $this->assertEquals(true, strlen($shopName) > 100);
        new ShopifyClient($shopName, ".myshopify.com");
    }

    public function testSetShopnameNotMyshopify()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShopifyClient("abfc3re34wr43f5g2dgf432", "josh.joshstore.com");
    }

    public function testSetInvalidAccessToken()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShopifyClient("has space", "040350450399894.myshopify.com");
    }

    public function testSetEmptyAccessToken()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShopifyClient("", "040350450399894.myshopify.com");
    }

    public function testAccessTokenRejectsHeaderInjection()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShopifyClient("shpat_abc\r\nX-Evil: 1", "040350450399894.myshopify.com");
    }

    public function testAccessTokenTooLong()
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShopifyClient(str_repeat('a', 1025), "040350450399894.myshopify.com");
    }

    public function testAcceptsCurrentAndOpaqueTokenFormats()
    {
        foreach (['shpat_' . str_repeat('0a', 16), str_repeat('f', 32), 'opaque.Token-with~chars+/=', str_repeat('a', 1024)] as $token) {
            $client = new ShopifyClient($token, "040350450399894.myshopify.com");
            $client->setHttpClient(new MockRequest());
            $this->assertSame('X-Shopify-Access-Token: ' . $token, $client->call('GET', 'shop')[2][1]);
        }
    }

    public function testRequestOnlyAcceptsValidMethods()
    {
        $this->expectException(\InvalidArgumentException::class);
        $client = new ShopifyClient("abc", "040350450399894.myshopify.com");
        $client->call("OPTIONS", "https://040350450399894.myshopify.com/admin/products.json", null, null);
    }

    public function testCall()
    {
        $client = new ShopifyClient("abnini3ruin4ruinc", "040350450399894.myshopify.com");
        $client->setHttpClient(new MockRequest());
        $response = $client->call("PUT", "messages/3094304723/templates", ['test' => 'call'], ['since_id' => 4]);
        $this->assertEquals("PUT", $response[0]);
        $this->assertEquals("https://040350450399894.myshopify.com/admin/api/" . ShopifyClient::$apiVersion . "/messages/3094304723/templates.json", $response[1]);
        $this->assertEquals(['Content-Type: application/json', 'X-Shopify-Access-Token: abnini3ruin4ruinc'], $response[2]);
        $this->assertEquals(['test' => 'call'], $response[3]);
        $this->assertEquals(['since_id' => 4], $response[4]);
    }

    public function testSetShopNameAndAccessToken()
    {
        $client = new ShopifyClient("abnini3ruin4ruinc", "040350450399894.myshopify.com");
        $client->setHttpClient(new MockRequest());
        $client->setShopName('4234322.myshopify.com');
        $response = $client->call("PUT", "messages/3094304723/templates", ['test' => 'call'], ['since_id' => 4]);
        $this->assertEquals("https://4234322.myshopify.com/admin/api/" . ShopifyClient::$apiVersion . "/messages/3094304723/templates.json", $response[1]);
        $this->assertEquals(['Content-Type: application/json', 'X-Shopify-Access-Token: abnini3ruin4ruinc'], $response[2]);
        $client->setAccessToken('xyzrf43f4ff434t43');
        $response = $client->call("PUT", "messages/3094304723/templates", ['test' => 'call'], ['since_id' => 4]);
        $this->assertEquals("https://4234322.myshopify.com/admin/api/" . ShopifyClient::$apiVersion . "/messages/3094304723/templates.json", $response[1]);
        $this->assertEquals(['Content-Type: application/json', 'X-Shopify-Access-Token: xyzrf43f4ff434t43'], $response[2]);
    }
}
