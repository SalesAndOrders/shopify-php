<?php

namespace Shopify\Auth;

use Psr\Log\LoggerInterface;

/**
 * Builds the OAuth client and token provider from the `shopify` config section:
 * api_key, api_secret, jwt_kid, jwt_private_key, token_strategy.
 *
 * The signing key is parsed lazily, on the first mint: a missing or broken key fails
 * that mint (which falls back to the legacy token if there is one), never construction.
 */
final class TokenProviderFactory
{
    public static function oauthClient(array $config, ?LoggerInterface $logger = null, ?HttpTransportInterface $http = null): OAuthClient
    {
        $clientId = $config['api_key'] ?? null;
        if (!is_string($clientId) || $clientId === '') {
            throw new \InvalidArgumentException('shopify.api_key is missing');
        }
        $secret = is_string($config['api_secret'] ?? null) ? $config['api_secret'] : null;
        $kid = $config['jwt_kid'] ?? null;
        $pem = $config['jwt_private_key'] ?? null;

        $loader = static function () use ($clientId, $kid, $pem): ClientAssertionFactory {
            if (!is_string($kid) || $kid === '' || !is_string($pem) || $pem === '') {
                throw new \InvalidArgumentException('shopify.jwt_kid / shopify.jwt_private_key are not configured');
            }
            return new ClientAssertionFactory($clientId, $kid, new PemAssertionSigner($pem));
        };

        return new OAuthClient($clientId, $secret, $loader, $http, $logger);
    }

    public static function tokenProvider(array $config, ?LoggerInterface $logger = null, ?HttpTransportInterface $http = null): TokenProvider
    {
        $strategy = is_string($config['token_strategy'] ?? null) ? $config['token_strategy'] : null;
        return new TokenProvider(self::oauthClient($config, $logger, $http), $strategy, $logger);
    }
}
