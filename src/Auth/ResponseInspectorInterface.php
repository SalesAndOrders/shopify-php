<?php

namespace Shopify\Auth;

/**
 * Tells AuthenticatedCall what an Admin API call's outcome was. Each client library
 * reports errors differently (returned response vs thrown exception).
 */
interface ResponseInspectorInterface
{
    /** HTTP status of the outcome, or null if unknown. */
    public function status(mixed $result, ?\Throwable $error): ?int;

    /** Response body (or error text) of the outcome; '' if unknown. */
    public function body(mixed $result, ?\Throwable $error): string;
}
