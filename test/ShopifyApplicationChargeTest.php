<?php
/**
 * @copyright Copyright (c) 2017 Shopify Inc.
 * @license MIT
 */

namespace Shopify;

class ShopifyApplicationChargeTest extends \PHPUnit_Framework_TestCase
{
    private $mockClient;

    public function setUp()
    {
        $this->mockClient = $this->getMockBuilder('Shopify\ShopifyClient')
            ->setConstructorArgs(['abc', '040350450399894.myshopify.com'])
            ->getMock();
    }

    public function testReadList()
    {
        $this->mockClient->expects($this->once())
            ->method('call')
            ->with('GET', 'application_charges', null, []);
        $this->mockClient->application_charges->readList();
    }

    public function testRead()
    {
        $this->mockClient->expects($this->once())
            ->method('call')
            ->with('GET', 'application_charges/123', null);
        $this->mockClient->application_charges->read(123);
    }

    public function testCreate()
    {
        $applicationCharges = [
            "name" => "The Amazing Franco Plan",
            "price" => 4.00,
            "return_url" => "http://theAmazingFrancoPlan.com",
            "test" => true
        ];
        $this->mockClient->expects($this->once())
            ->method('call')
            ->with('POST', 'application_charges', ["application_charge" => $applicationCharges]);
        $this->mockClient->application_charges->create([
            "name" => "The Amazing Franco Plan",
            "price" => 4.00,
            "return_url" => "http://theAmazingFrancoPlan.com",
            "test" => true
        ]);
    }

    public function testCustomCreate()
    {
        $applicationCharges = [
            "description" => "The Amazing Franco Plan",
            "price" => 1.0
        ];
        $this->mockClient->expects($this->once())
            ->method('call')
            ->with('POST', 'application_charges/123/extra_suffix', $applicationCharges);
        $this->mockClient->application_charges->customCreate(
            [
                "description" => "The Amazing Franco Plan",
                "price" => 1.0
            ],
            '123',
            'extra_suffix'
        );
    }
}
