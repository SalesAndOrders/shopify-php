<?php

namespace Shopify\Auth;

interface AssertionSignerInterface
{
    /** Returns the raw RS256 signature of $input. */
    public function sign(string $input): string;
}
