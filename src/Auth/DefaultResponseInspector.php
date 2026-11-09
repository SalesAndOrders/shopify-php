<?php

namespace Shopify\Auth;

/**
 * Duck-typed inspector for the clients we use:
 * - this library's responses: httpStatus() / body();
 * - PSR-7 responses: getStatusCode() / getBody();
 * - exceptions carrying a response (Guzzle and clients built on it): getResponse().
 */
final class DefaultResponseInspector implements ResponseInspectorInterface
{
    public function status(mixed $result, ?\Throwable $error): ?int
    {
        $subject = $error !== null ? $this->responseOf($error) : $result;
        if (!is_object($subject)) {
            return null;
        }
        if (method_exists($subject, 'httpStatus')) {
            $s = $subject->httpStatus();
            return is_numeric($s) ? (int) $s : null;
        }
        if (method_exists($subject, 'getStatusCode')) {
            $s = $subject->getStatusCode();
            return is_numeric($s) ? (int) $s : null;
        }
        return null;
    }

    public function body(mixed $result, ?\Throwable $error): string
    {
        $subject = $error !== null ? $this->responseOf($error) : $result;
        $body = '';
        if (is_object($subject)) {
            if (method_exists($subject, 'body')) {
                $body = (string) $subject->body();
            } elseif (method_exists($subject, 'getBody')) {
                $body = (string) $subject->getBody();
            }
        }
        if ($error !== null) {
            $body .= ' ' . $error->getMessage();
        }
        return $body;
    }

    private function responseOf(\Throwable $error): mixed
    {
        if (method_exists($error, 'getResponse')) {
            try {
                return $error->getResponse();
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }
}
