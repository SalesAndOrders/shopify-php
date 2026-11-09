<?php

namespace Shopify\Auth;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Hands out the access token for a store (docs/SHOPIFY_OFFLINE.md §5.3 in Admin).
 *
 * Build a new provider on every invocation or request, from that invocation's config,
 * so a new key or kill-switch value applies at once. Only the in-memory token map is
 * static and outlives it (warm Lambda containers, long-running scripts).
 *
 * Log events: `shopify.token` with context source=app_only|legacy, result=ok|failed,
 * reason=kill_switch|mint_failed. Legacy use after Dec 1 2026 counts against compliance.
 */
final class TokenProvider
{
    public const STRATEGY_EXPIRING = 'expiring';
    public const STRATEGY_LEGACY = 'legacy';

    /** @var array<string, TokenSet> */
    private static array $memory = [];

    private string $strategy;
    private LoggerInterface $logger;
    /** @var callable(): int */
    private $clock;

    /**
     * @param string|null $strategy shopify.token_strategy. Anything but 'expiring' (including missing) means 'legacy'.
     */
    public function __construct(
        private readonly OAuthClient $oauth,
        ?string $strategy,
        ?LoggerInterface $logger = null,
        ?callable $clock = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? 'time';
        $this->strategy = $strategy === self::STRATEGY_EXPIRING ? self::STRATEGY_EXPIRING : self::STRATEGY_LEGACY;
        if ($strategy !== null && $strategy !== '' && $strategy !== self::STRATEGY_EXPIRING && $strategy !== self::STRATEGY_LEGACY) {
            $this->logger->warning('shopify.token_strategy_unknown', ['value' => substr($strategy, 0, 32), 'using' => self::STRATEGY_LEGACY]);
        }
    }

    public function strategy(): string
    {
        return $this->strategy;
    }

    public function getAccessToken(ShopifyAccount $account): string
    {
        if (!$account->isPublicApp()) {
            if ($account->source === ShopifyAccount::SOURCE_SHOPIFY) {
                // A merchant's own custom app: out of scope, keep using its pasted token.
                if ($account->legacyToken !== null) {
                    return $account->legacyToken;
                }
                throw new TokenUnavailableException('Custom-app account has no token');
            }
            throw new UnsupportedSourceException('Unsupported account source: ' . $account->source);
        }

        if ($this->strategy === self::STRATEGY_LEGACY && $account->legacyToken !== null) {
            $this->logger->info('shopify.token', $this->ctx($account, ['source' => 'legacy', 'reason' => 'kill_switch']));
            return $account->legacyToken;
        }

        $now = (int) ($this->clock)();
        $key = self::key($account);
        if (isset(self::$memory[$key]) && self::$memory[$key]->isFresh($now)) {
            return self::$memory[$key]->token;
        }

        try {
            $cached = $account->cache->get();
        } catch (\Throwable $e) {
            $this->logger->error('shopify.token_cache', $this->ctx($account, ['op' => 'read', 'error' => $e->getMessage()]));
            $cached = null;
        }
        if ($cached !== null && $cached->isFresh($now)) {
            self::$memory[$key] = $cached;
            return $cached->token;
        }

        return $this->mintOrFallback($account);
    }

    /**
     * After the Admin API rejected a minted token (401): drop it everywhere and mint again.
     * Falls back to the legacy token when minting fails.
     */
    public function forceRenew(ShopifyAccount $account): string
    {
        $this->forget($account);
        try {
            $account->cache->clear();
        } catch (\Throwable $e) {
            $this->logger->error('shopify.token_cache', $this->ctx($account, ['op' => 'clear', 'error' => $e->getMessage()]));
        }
        return $this->mintOrFallback($account);
    }

    public function forget(ShopifyAccount $account): void
    {
        unset(self::$memory[self::key($account)]);
    }

    /** For tests. */
    public static function resetMemory(): void
    {
        self::$memory = [];
    }

    private function mintOrFallback(ShopifyAccount $account): string
    {
        try {
            $token = $this->oauth->clientCredentials($account->shop);
        } catch (\Throwable $e) {
            $this->logger->error('shopify.token', $this->ctx($account, [
                'source' => 'app_only',
                'result' => 'failed',
                'error' => $e->getMessage(),
                'status' => $e instanceof TokenRequestException ? $e->status : null,
            ]));
            if ($account->legacyToken !== null) {
                $this->logger->warning('shopify.token', $this->ctx($account, ['source' => 'legacy', 'reason' => 'mint_failed']));
                return $account->legacyToken;
            }
            throw new TokenUnavailableException('Could not mint a Shopify token: ' . $e->getMessage(), 0, $e);
        }

        self::$memory[self::key($account)] = $token;
        try {
            $account->cache->put($token);
        } catch (\Throwable $e) {
            $this->logger->error('shopify.token_cache', $this->ctx($account, ['op' => 'write', 'error' => $e->getMessage()]));
        }
        $this->logger->info('shopify.token', $this->ctx($account, [
            'source' => 'app_only',
            'result' => 'ok',
            'kid' => $this->oauth->kid(),
            'expires_in' => $token->expiresAt - (int) ($this->clock)(),
        ]));
        return $token->token;
    }

    private static function key(ShopifyAccount $account): string
    {
        return $account->accountId . '|' . $account->shop;
    }

    private function ctx(ShopifyAccount $account, array $extra): array
    {
        return ['account' => $account->accountId, 'shop' => $account->shop] + $extra;
    }
}
