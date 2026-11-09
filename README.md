
# 🚧 Work in progress 🚧

[![Build Status](https://travis-ci.org/Shopify/shopify-php.svg?branch=master)](https://travis-ci.org/Shopify/shopify-php) [![codecov](https://codecov.io/gh/Shopify/shopify-php/branch/master/graph/badge.svg)](https://codecov.io/gh/Shopify/shopify-php)


## Getting started

```php
<?php
require 'vendor/autoload.php'; 

use Shopify\ShopifyClient;

$client = new ShopifyClient($access_token, "yourshop.myshopify.com");
$products = $client->products->readList();

```

## Creating orders

```php
$newOrder = ['line_items' => [['title' => 'cool', 'price' => 4]]];
$response = $client->orders->create($newOrder);
```

## Reading orders

```php
$response = $client->orders->read($orderId);
$object = $response->parsedResponse();
```

```php
$orders = $client->orders->readList();
foreach ($order in $orders->parsedResponse()) {
    var_dump($order->id);
}
```

## Updating orders

```php
$response = $client->orders->update($orderId, ["note" => "cool order"]);
```

## Counting open orders

```php
$response = $client->orders->readCount(["status" => "open"]);
```

## Deleting orders

```php
$response = $client->orders->delete($orderId);
```

## Expiring access tokens (`Shopify\Auth`)

Shopify stops accepting non-expiring offline tokens for public apps on 2027-04-01.
`Shopify\Auth` gets short-lived **application-only** tokens (client credentials with an
RS256 client assertion), caches them per store, and falls back to the store's legacy
token while one still exists. The plan is in Admin's `docs/SHOPIFY_OFFLINE.md`.

```php
use Shopify\Auth\{AuthenticatedCall, PdoSettingTokenCache, ShopifyAccount, TokenProviderFactory};

// Build per invocation/request from that invocation's `shopify` config section:
// api_key, api_secret, jwt_kid, jwt_private_key, token_strategy ('expiring' | 'legacy'; missing = legacy).
$provider = TokenProviderFactory::tokenProvider($config['shopify'], $psrLogger);

$account = new ShopifyAccount(
    $accountId,                                  // also the store's database name
    $source,                                     // accounts.source: shopify_app | shopify
    $shopifyStoreId,                             // STORE.myshopify.com
    $settings['shopify_refresh_token'] ?? null,  // legacy token (empty once hidden)
    $settings['shopify_auth'] ?? null,           // app | custom
    new PdoSettingTokenCache($pdo, $accountId, $settings['shopify_access_token'] ?? null),
);

$response = (new AuthenticatedCall($provider, null, $psrLogger))->run($account, function (string $token) use ($shop) {
    return (new \Shopify\ShopifyClient($token, $shop))->call('GET', 'shop');
});
```

- `AuthenticatedCall` handles a 401 by minting again and retrying once, and logs CRITICAL on
  Shopify's "Non-expiring access tokens are no longer accepted" 403.
- `OAuthClient::exchangeCode($shop, $code)` is the install-time code grant with `expiring=1`.
- Tokens never appear in logs, exception messages or `var_dump` output.

--- 

## Testing

```bash
composer install
vendor/bin/phpunit
```

The `PdoSettingTokenCache` tests need a **local** MySQL and are skipped otherwise:

```bash
SHOPIFY_PHP_TEST_MYSQL="mysql:host=127.0.0.1;port=3306" SHOPIFY_PHP_TEST_MYSQL_USER=root SHOPIFY_PHP_TEST_MYSQL_PASSWORD=root vendor/bin/phpunit
```
