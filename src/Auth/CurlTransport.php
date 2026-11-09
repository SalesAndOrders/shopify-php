<?php

namespace Shopify\Auth;

final class CurlTransport implements HttpTransportInterface
{
    public function postForm(string $url, array $form, array $headers, int $timeout): HttpResult
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        if ($body === false || curl_errno($ch) !== 0) {
            return new HttpResult(0, [], '', 'curl ' . curl_errno($ch) . ': ' . curl_error($ch));
        }
        return new HttpResult((int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $responseHeaders, (string) $body);
    }
}
