<?php

namespace Shopify\Auth;

final class HttpResult
{
    /**
     * @param int                  $status       HTTP status, or 0 on a network error/timeout
     * @param array<string,string> $headers      lower-cased names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $networkError = null,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function __debugInfo(): array
    {
        // A token response body holds a token.
        return ['status' => $this->status, 'body' => '[redacted len=' . strlen($this->body) . ']', 'networkError' => $this->networkError];
    }
}
