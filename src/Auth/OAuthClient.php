<?php

namespace Shopify\Auth;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Token requests to POST https://{shop}/admin/oauth/access_token (form-encoded).
 *
 * - clientCredentials(): application-only token via a signed client assertion. Retries
 *   429/5xx/network errors (at most 3 attempts), signing a NEW assertion every attempt.
 * - exchangeCode(): authorization code grant at install, with expiring=1. Never retried
 *   (a code is single-use).
 */
final class OAuthClient
{
    public const MAX_ATTEMPTS = 3;
    /** Back-off before attempt 2 and 3 on 5xx/network errors (seconds). */
    private const BACKOFF = [1, 2];
    private const DEFAULT_RETRY_AFTER = 2;
    private const MAX_RETRY_AFTER = 30;
    private const SHOP_PATTERN = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/';

    private ?ClientAssertionFactory $assertions = null;
    /** @var (\Closure(): ClientAssertionFactory)|null */
    private ?\Closure $assertionsLoader = null;
    private HttpTransportInterface $http;
    private LoggerInterface $logger;
    /** @var callable(): int */
    private $clock;
    /** @var callable(int): void */
    private $sleep;

    /**
     * @param ClientAssertionFactory|\Closure|null $assertions A factory, or a closure that builds one on
     *        first use, so a missing or broken key fails only mints, never construction.
     */
    public function __construct(
        private readonly string $clientId,
        private readonly ?string $clientSecret,
        ClientAssertionFactory|\Closure|null $assertions,
        ?HttpTransportInterface $http = null,
        ?LoggerInterface $logger = null,
        ?callable $clock = null,
        ?callable $sleep = null,
        private readonly int $timeout = 15,
    ) {
        if ($assertions instanceof \Closure) {
            $this->assertionsLoader = $assertions;
        } else {
            $this->assertions = $assertions;
        }
        $this->http = $http ?? new CurlTransport();
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? 'time';
        $this->sleep = $sleep ?? static function (int $s): void {
            sleep($s);
        };
    }

    public static function normalizeShop(string $shop): string
    {
        $shop = strtolower(trim($shop));
        if (!preg_match(self::SHOP_PATTERN, $shop)) {
            throw new \InvalidArgumentException('Shop must be a STORE.myshopify.com domain');
        }
        return $shop;
    }

    /** The kid in use, once the assertion factory has been built. */
    public function kid(): ?string
    {
        return $this->assertions?->kid();
    }

    public function clientCredentials(string $shop): TokenSet
    {
        $shop = self::normalizeShop($shop);
        $last = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $t0 = (int) ($this->clock)();
            $result = $this->http->postForm($this->tokenUrl($shop), [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
                'client_assertion' => $this->assertions()->create($shop),
            ], ['Accept: application/json'], $this->timeout);

            if ($result->status === 200) {
                return $this->parseTokenResponse($result, $t0, true);
            }
            $last = $this->failure($result);
            if (!$last->retryable || $attempt === self::MAX_ATTEMPTS) {
                throw $last;
            }
            if ($result->status === 429) {
                $wait = $this->retryAfter($result);
                $this->logger->warning('shopify.token_429', ['shop' => $shop, 'attempt' => $attempt, 'retry_after' => $wait]);
            } else {
                $wait = self::BACKOFF[$attempt - 1];
            }
            ($this->sleep)($wait);
        }
        throw $last; // unreachable
    }

    public function exchangeCode(string $shop, string $code, bool $expiring = true): TokenSet
    {
        $shop = self::normalizeShop($shop);
        if ($this->clientSecret === null || $this->clientSecret === '') {
            throw new \LogicException('exchangeCode needs the client secret');
        }
        $form = ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret, 'code' => $code];
        if ($expiring) {
            $form['expiring'] = '1';
        }
        $t0 = (int) ($this->clock)();
        $result = $this->http->postForm($this->tokenUrl($shop), $form, ['Accept: application/json'], $this->timeout);
        if ($result->status !== 200) {
            throw $this->failure($result);
        }
        return $this->parseTokenResponse($result, $t0, $expiring);
    }

    private function assertions(): ClientAssertionFactory
    {
        if ($this->assertions === null) {
            try {
                $loaded = $this->assertionsLoader !== null ? ($this->assertionsLoader)() : null;
            } catch (\Throwable $e) {
                throw new TokenRequestException('Client assertion unavailable: ' . $e->getMessage(), 0, 'assertion_unavailable', false, $e);
            }
            if (!$loaded instanceof ClientAssertionFactory) {
                throw new TokenRequestException('Client assertion unavailable: no signing key configured', 0, 'assertion_unavailable', false);
            }
            $this->assertions = $loaded;
        }
        return $this->assertions;
    }

    private function tokenUrl(string $shop): string
    {
        return 'https://' . $shop . '/admin/oauth/access_token';
    }

    private function parseTokenResponse(HttpResult $result, int $t0, bool $expiring): TokenSet
    {
        $d = json_decode($result->body, true);
        $token = is_array($d) ? ($d['access_token'] ?? null) : null;
        $scope = is_array($d) && is_string($d['scope'] ?? null) ? $d['scope'] : '';
        $expiresIn = is_array($d) ? ($d['expires_in'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            throw new TokenRequestException('Shopify token response has no access_token', 200, 'unexpected_response', false);
        }
        if (!$expiring && $expiresIn === null) {
            return TokenSet::nonExpiring($token, $scope);
        }
        if (!is_numeric($expiresIn) || (int) $expiresIn <= 0) {
            throw new TokenRequestException('Shopify token response has no valid expires_in', 200, 'unexpected_response', false);
        }
        return TokenSet::fromLifetime($token, $t0, (int) $expiresIn, $scope);
    }

    private function failure(HttpResult $result): TokenRequestException
    {
        if ($result->status === 0) {
            return new TokenRequestException('Shopify token request failed: ' . $result->networkError, 0, 'network', true);
        }
        $d = json_decode($result->body, true);
        $code = null;
        $desc = '';
        if (is_array($d)) {
            $code = is_string($d['error'] ?? null) ? $d['error'] : null;
            $desc = is_string($d['error_description'] ?? null) ? $d['error_description']
                : (is_string($d['errors'] ?? null) ? $d['errors'] : '');
        }
        $retryable = $result->status === 429 || $result->status >= 500;
        $msg = sprintf('Shopify token request failed: http=%d%s%s', $result->status,
            $code !== null ? ' error=' . $code : '',
            $desc !== '' ? ' (' . substr($desc, 0, 200) . ')' : '');
        return new TokenRequestException($msg, $result->status, $code, $retryable);
    }

    private function retryAfter(HttpResult $result): int
    {
        $h = $result->header('retry-after');
        $s = ($h !== null && ctype_digit($h)) ? (int) $h : self::DEFAULT_RETRY_AFTER;
        return max(1, min(self::MAX_RETRY_AFTER, $s));
    }
}
