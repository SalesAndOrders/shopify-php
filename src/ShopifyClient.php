<?php
/**
 * @copyright Copyright (c) 2017 Shopify Inc.
 * @license MIT
 */

namespace Shopify;

/**
 * Class ShopifyClient
 * @package Shopify
 */
class ShopifyClient
{
    private $accessToken;
    private $shopName;
    private $httpClient;

    /**
     * @var string
     */
    public static $apiVersion = '2020-10';

    /**
     * @var string[]
     */
    private static $resources = [
        "order",
        "fulfillment",
        "checkout",
        "fulfillment_event",
        "product",
        "shop",
        "variant",
        "recurring_application_charge",
        "application_charge",
        "custom_collection",
        "smart_collection",
        "collect"
    ];

    /**
     * ShopifyClient constructor.
     * @param $accessToken
     * @param $shopName
     * @param null $newApiVersion
     */
    public function __construct($accessToken, $shopName, $newApiVersion = null)
    {
        foreach (self::$resources as $resource) {
            $className = 'Shopify\Shopify' . str_replace("_", "", ucwords($resource, "_"));
            $this->{$resource . "s"} = new $className($this);
        }
        if (!empty($apiVersion)) {
            $this::$apiVersion = $newApiVersion;
        }
        $this->setAccessToken($accessToken);
        $this->setShopName($shopName);
        $this->setHttpClient();
    }

    /**
     * @param $accessToken
     */
    public function setAccessToken($accessToken)
    {
        if (preg_match('/^([a-zA-Z0-9_]{10,100})$/', $accessToken)===0) {
            throw new \InvalidArgumentException("Access token should be between 10 and 100 letters and numbers");
        }
        $this->accessToken = $accessToken;
    }

    /**
     * @param $shopName
     */
    public function setShopName($shopName)
    {
        if (!$this->isValidShopName($shopName)) {
            throw new \InvalidArgumentException(
                'Shop name should be 3-100 letters, numbers, or hyphens e.g. your-store.myshopify.com'
            );
        }
        $this->shopName = $shopName;
    }

    /**
     * @param $shopName
     * @return bool
     */
    private function isValidShopName($shopName)
    {
        if (preg_match('/^[a-zA-Z0-9\-]{3,100}\.myshopify\.(?:com|io)$/', $shopName)) {
            return true;
        }
        return false;
    }

    /**
     * @param $resource
     * @return string
     */
    private function uriBuilder($resource)
    {
        return sprintf('https://%s/admin/api/%s/%s.json', $this->shopName, self::$apiVersion, $resource);
    }

    /**
     * @return string[]
     */
    private function authHeaders()
    {
        return [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $this->accessToken
        ];
    }

    /**
     * @param $method
     * @param $resource
     * @param null $payload
     * @param array $parameters
     * @return mixed
     */
    public function call($method, $resource, $payload = null, $parameters = [])
    {
        if (!in_array($method, ["POST", "PUT", "PATCH", "GET", "DELETE", "HEAD"], true)) {
            throw new \InvalidArgumentException("Method not valid");
        }
        return $this->httpClient->request(
            $method,
            $this->uriBuilder($resource),
            $this->authHeaders(),
            $payload,
            $parameters
        );
    }

    /**
     * @param HttpRequestInterface|null $client
     */
    public function setHttpClient(HttpRequestInterface $client = null)
    {
        $this->httpClient = ($client ? $client : new CurlRequest());
    }

    /**
     * @return CurlResponse
     */
    public function uninstallAccount()
    {
        $revoke_url   = "https://{$this->shopName}/admin/api_permissions/current.json";

        $headers = array(
            "Content-Type: application/json",
            "Accept: application/json",
            "Content-Length: 0",
            "X-Shopify-Access-Token: " . $this->accessToken
        );

        $handler = curl_init($revoke_url);
        curl_setopt($handler, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($handler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handler, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($handler);
        if(!curl_errno($handler))
        {
            $info = curl_getinfo($handler);
        }

        curl_close($handler);
        return new CurlResponse($response);
    }
}
