<?php

namespace Shopify\Auth;

interface HttpTransportInterface
{
    /**
     * POSTs $form as application/x-www-form-urlencoded. Never throws for HTTP or
     * network errors: a network error is an HttpResult with status 0.
     *
     * @param array<string,string> $form
     * @param string[]             $headers "Name: value" lines
     */
    public function postForm(string $url, array $form, array $headers, int $timeout): HttpResult;
}
