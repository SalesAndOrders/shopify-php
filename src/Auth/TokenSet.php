<?php

namespace Shopify\Auth;

/**
 * An access token with its expiry and planned renewal time (unix seconds).
 *
 * Deliberately not JsonSerializable and redacted in var_dump/print_r, so a token
 * can't end up in a log by accident. Use toJson() to persist it.
 */
final class TokenSet
{
    /** Renew this long before expiry: longer than the 15-minute Lambda maximum. */
    public const RENEW_LEAD = 960;
    /** Extra random lead, so workers don't renew in lockstep. */
    public const RENEW_JITTER_MAX = 300;

    public function __construct(
        public readonly string $token,
        public readonly int $expiresAt,
        public readonly int $renewAt,
        public readonly string $scope = '',
    ) {
        if ($token === '') {
            throw new \InvalidArgumentException('Access token is empty');
        }
    }

    /**
     * @param int      $t0       Time the token request was sent.
     * @param int      $lifetime The response's expires_in.
     * @param int|null $jitter   Seconds of extra lead (random 0..RENEW_JITTER_MAX when null).
     */
    public static function fromLifetime(string $token, int $t0, int $lifetime, string $scope = '', ?int $jitter = null): self
    {
        if ($lifetime <= 0) {
            throw new \InvalidArgumentException('expires_in must be positive');
        }
        $jitter ??= random_int(0, self::RENEW_JITTER_MAX);
        $expiresAt = $t0 + $lifetime;
        // The lifetime/2 cap prevents a mint storm if Shopify ever returns a short expires_in.
        $lead = min(self::RENEW_LEAD + $jitter, intdiv($lifetime, 2));
        return new self($token, $expiresAt, $expiresAt - $lead, $scope);
    }

    /** A token that never expires (non-expiring code-grant tokens). */
    public static function nonExpiring(string $token, string $scope = ''): self
    {
        return new self($token, PHP_INT_MAX, PHP_INT_MAX, $scope);
    }

    public function isFresh(int $now): bool
    {
        return $now < $this->renewAt;
    }

    public function toJson(): string
    {
        return json_encode([
            'token' => $this->token,
            'expires_at' => $this->expiresAt,
            'renew_at' => $this->renewAt,
            'scope' => $this->scope,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Returns null for anything that isn't a well-formed stored token. */
    public static function fromJson(?string $json): ?self
    {
        if ($json === null || $json === '') {
            return null;
        }
        $d = json_decode($json, true);
        if (!is_array($d)
            || !is_string($d['token'] ?? null) || $d['token'] === ''
            || !is_int($d['expires_at'] ?? null)
            || !is_int($d['renew_at'] ?? null)
        ) {
            return null;
        }
        return new self($d['token'], $d['expires_at'], $d['renew_at'], is_string($d['scope'] ?? null) ? $d['scope'] : '');
    }

    public function __debugInfo(): array
    {
        return [
            'token' => '[redacted len=' . strlen($this->token) . ']',
            'expiresAt' => $this->expiresAt,
            'renewAt' => $this->renewAt,
            'scope' => $this->scope,
        ];
    }
}
