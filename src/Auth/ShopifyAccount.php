<?php

namespace Shopify\Auth;

/**
 * What the token provider needs to know about one store.
 */
final class ShopifyAccount
{
    public const SOURCE_PUBLIC_APP = 'shopify_app';
    public const SOURCE_SHOPIFY = 'shopify';
    public const AUTH_APP = 'app';
    public const AUTH_CUSTOM = 'custom';

    public readonly string $shop;
    public readonly ?string $legacyToken;
    public readonly ?string $authMarker;

    /**
     * @param string      $source      accounts.source
     * @param string      $shop        accounts.shopify_store_id (STORE.myshopify.com)
     * @param string|null $legacyToken setting shopify_refresh_token (empty once hidden)
     * @param string|null $authMarker  setting shopify_auth: 'app' | 'custom' | null
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $source,
        string $shop,
        ?string $legacyToken,
        ?string $authMarker,
        public readonly TokenCacheInterface $cache,
    ) {
        $this->shop = OAuthClient::normalizeShop($shop);
        $this->legacyToken = ($legacyToken === null || $legacyToken === '') ? null : $legacyToken;
        $this->authMarker = ($authMarker === null || $authMarker === '') ? null : $authMarker;
    }

    /** Public-app installs mint tokens; custom-app connections keep their pasted token. */
    public function isPublicApp(): bool
    {
        return $this->source === self::SOURCE_PUBLIC_APP
            || ($this->source === self::SOURCE_SHOPIFY && $this->authMarker === self::AUTH_APP);
    }

    public function isLegacyToken(string $token): bool
    {
        return $this->legacyToken !== null && hash_equals($this->legacyToken, $token);
    }

    public function __debugInfo(): array
    {
        return [
            'accountId' => $this->accountId,
            'source' => $this->source,
            'shop' => $this->shop,
            'legacyToken' => $this->legacyToken === null ? null : '[redacted]',
            'authMarker' => $this->authMarker,
        ];
    }
}
