<?php
/**
 * @copyright Copyright (c) 2017 Shopify Inc.
 * @license MIT
 */

namespace Shopify;

class CurlRequestTest extends \PHPUnit\Framework\TestCase
{
    public function testRequest()
    {
        $stub = $this->
        getMockBuilder('Shopify\CurlRequest')
            ->onlyMethods(['setCurlOpt', 'execute', 'curlInit'])
            ->getMock();
        $stub->method('execute')->willReturn("HTTP/1.1 201 Created\r\nServer: nginx\r\n\r\n{\"count\": 1}");
        $stub->method('curlInit')->willReturn(123);
        $client = new ShopifyClient('a1b5f44g5y4544252c456', '040350450399894.myshopify.com');
        $client->setHttpClient($stub);
        $stub->expects($this->atLeast(1))->method("curlInit")->with("https://040350450399894.myshopify.com/admin/api/" . ShopifyClient::$apiVersion . "/orders/123.json?created_at_max=2018-04-25T16%3A15%3A47-04%3A00");
        $headers = ['Content-Type: application/json', 'X-Shopify-Access-Token: a1b5f44g5y4544252c456'];
        $opts = [];
        $stub->method("setCurlOpt")->willReturnCallback(function ($ch, $type, $value) use (&$opts) {
            $opts[] = [$ch, $type, $value];
        });
        $stub->expects($this->once())->method("execute")->with(123);
        $client->call("PUT", "orders/123", ['order' => ['note' => 'special order!']], ['created_at_max' => '2018-04-25T16:15:47-04:00']);
        $this->assertSame([
            [123, CURLOPT_POSTFIELDS, '{"order":{"note":"special order!"}}'],
            [123, CURLOPT_CUSTOMREQUEST, 'PUT'],
            [123, CURLOPT_HTTPHEADER, $headers],
            [123, CURLOPT_RETURNTRANSFER, true],
            [123, CURLOPT_HEADER, 1],
        ], $opts);
    }
}
