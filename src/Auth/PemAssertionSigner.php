<?php

namespace Shopify\Auth;

/**
 * Signs client assertions with an RSA private key (PEM). Never exposes the key.
 */
final class PemAssertionSigner implements AssertionSignerInterface
{
    private \OpenSSLAsymmetricKey $key;

    public function __construct(string $pem)
    {
        // Tolerate a PEM whose newlines were stored as literal "\n" sequences.
        if (!str_contains($pem, "\n") && str_contains($pem, '\n')) {
            $pem = str_replace('\n', "\n", $pem);
        }
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            while (openssl_error_string() !== false) {
                // drain OpenSSL's error queue
            }
            throw new \InvalidArgumentException('Shopify JWT private key does not parse');
        }
        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($details['bits'] ?? 0) < 2048) {
            throw new \InvalidArgumentException('Shopify JWT private key must be RSA, at least 2048 bits');
        }
        $this->key = $key;
    }

    public function sign(string $input): string
    {
        if (!openssl_sign($input, $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('openssl_sign failed');
        }
        return $signature;
    }

    public function __debugInfo(): array
    {
        return ['key' => '[redacted]'];
    }
}
