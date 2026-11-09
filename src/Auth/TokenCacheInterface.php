<?php

namespace Shopify\Auth;

/** The shared, per-store token cache (one per account). */
interface TokenCacheInterface
{
    public function get(): ?TokenSet;

    public function put(TokenSet $token): void;

    public function clear(): void;
}
