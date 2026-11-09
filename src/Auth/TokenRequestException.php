<?php

namespace Shopify\Auth;

/**
 * A token request failed. The message never contains a token or the assertion.
 */
class TokenRequestException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode,
        public readonly bool $retryable,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
