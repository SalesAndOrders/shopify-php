<?php

namespace Shopify\Auth;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs an Admin API call with the right token and handles rejection:
 * - 401 with a minted token: drop it, mint again, retry once; if that is rejected too
 *   and the store still has a legacy token, retry once more with the legacy token.
 * - 403 "Non-expiring access tokens are no longer accepted": logged as CRITICAL.
 * - 402 (frozen store) and everything else: returned as-is; the token is kept.
 * The call's own result is returned, or its exception rethrown.
 */
final class AuthenticatedCall
{
    public const NON_EXPIRING_PHRASE = 'Non-expiring access tokens are no longer accepted';

    private ResponseInspectorInterface $inspector;
    private LoggerInterface $logger;

    public function __construct(
        private readonly TokenProvider $provider,
        ?ResponseInspectorInterface $inspector = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->inspector = $inspector ?? new DefaultResponseInspector();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @template T
     * @param callable(string): T $call receives the access token
     * @return T
     */
    public function run(ShopifyAccount $account, callable $call): mixed
    {
        $token = $this->provider->getAccessToken($account);
        [$result, $error] = $this->attempt($call, $token);

        if ($this->isUnauthorized($result, $error) && !$account->isLegacyToken($token)) {
            $this->logger->warning('shopify.api_401', ['account' => $account->accountId, 'shop' => $account->shop, 'action' => 'renew']);
            $token = $this->provider->forceRenew($account);
            [$result, $error] = $this->attempt($call, $token);

            if ($this->isUnauthorized($result, $error) && !$account->isLegacyToken($token) && $account->legacyToken !== null) {
                $this->logger->warning('shopify.token', [
                    'account' => $account->accountId,
                    'shop' => $account->shop,
                    'source' => 'legacy',
                    'reason' => 'minted_token_rejected',
                ]);
                [$result, $error] = $this->attempt($call, $account->legacyToken);
            }
        }

        if ($this->inspector->status($result, $error) === 403
            && str_contains($this->inspector->body($result, $error), self::NON_EXPIRING_PHRASE)) {
            $this->logger->critical('shopify.non_expiring_rejected', ['account' => $account->accountId, 'shop' => $account->shop]);
        }

        if ($error !== null) {
            throw $error;
        }
        return $result;
    }

    /** @return array{0: mixed, 1: ?\Throwable} */
    private function attempt(callable $call, string $token): array
    {
        try {
            return [$call($token), null];
        } catch (\Throwable $e) {
            return [null, $e];
        }
    }

    private function isUnauthorized(mixed $result, ?\Throwable $error): bool
    {
        return $this->inspector->status($result, $error) === 401;
    }
}
