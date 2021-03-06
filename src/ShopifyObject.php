<?php
/**
 * @copyright Copyright (c) 2017 Shopify Inc.
 * @license MIT
 */

namespace Shopify;

class ShopifyObject
{
    protected $client;

    public function __construct(ShopifyClient $client)
    {
        $this->client = $client;
    }

    protected function get($id, $prefix = '')
    {
        $resource = $prefix . static::PLURAL . DIRECTORY_SEPARATOR . $id;
        return $this->client->call("GET", $resource, null, []);
    }

    protected function getList(array $options = [], $prefix = '')
    {
        $resource = $prefix . static::PLURAL;
        return $this->client->call("GET", $resource, null, $options);
    }

    protected function post($data, $prefix = '')
    {
        $resource = $prefix . static::PLURAL;
        return $this->client->call("POST", $resource, [static::SINGULAR => $data], []);
    }

    protected function postCustom(array $data, $id, $suffix = '')
    {
        $resource = sprintf(static::PLURAL . '/%s/' . $suffix, $id);
        return $this->client->call("POST", $resource, $data, []);
    }

    protected function delete($id, $prefix = '')
    {
        $resource = $prefix . static::PLURAL . DIRECTORY_SEPARATOR . $id;
        return $this->client->call("DELETE", $resource, null, []);
    }

    protected function put($id, $data, $prefix = '')
    {
        $resource = $prefix . static::PLURAL . DIRECTORY_SEPARATOR . $id;
        return $this->client->call("PUT", $resource, [static::SINGULAR => $data], []);
    }
}
