<?php

namespace Shopify\Test\Auth;

use Psr\Log\AbstractLogger;
use Shopify\Auth\HttpResult;
use Shopify\Auth\HttpTransportInterface;
use Shopify\Auth\TokenCacheInterface;
use Shopify\Auth\TokenSet;

/** Test helpers for the Auth tests. */
final class Support
{
    private static ?string $pem = null;

    /** A throwaway 2048-bit RSA key, generated once per test run. */
    public static function pem(): string
    {
        if (self::$pem === null) {
            $opts = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
            $key = @openssl_pkey_new($opts);
            if ($key === false) {
                // Windows PHP builds need an explicit openssl.cnf.
                $opts['config'] = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
                $key = openssl_pkey_new($opts);
            }
            if ($key === false || !openssl_pkey_export($key, $pem, null, $opts)) {
                throw new \RuntimeException('Cannot generate a test RSA key');
            }
            self::$pem = $pem;
        }
        return self::$pem;
    }

    public static function tokenJson(string $token, int $expiresIn = 3599): string
    {
        return json_encode(['access_token' => $token, 'scope' => 'read_products', 'expires_in' => $expiresIn]);
    }

    /** Decodes a JWT part (header or claims). */
    public static function jwtPart(string $jwt, int $i): array
    {
        $p = explode('.', $jwt)[$i];
        return json_decode(base64_decode(strtr($p, '-_', '+/') . str_repeat('=', (4 - strlen($p) % 4) % 4)), true);
    }
}

final class FakeTransport implements HttpTransportInterface
{
    /** @var list<array{url: string, form: array, headers: array}> */
    public array $requests = [];
    /** @var list<HttpResult> */
    private array $queue = [];

    public function push(int $status, string $body = '', array $headers = []): self
    {
        $this->queue[] = new HttpResult($status, $headers, $body, $status === 0 ? 'curl 28: timeout' : null);
        return $this;
    }

    public function postForm(string $url, array $form, array $headers, int $timeout): HttpResult
    {
        $this->requests[] = ['url' => $url, 'form' => $form, 'headers' => $headers];
        if ($this->queue === []) {
            throw new \LogicException('FakeTransport: unexpected request');
        }
        return array_shift($this->queue);
    }
}

final class MemoryCache implements TokenCacheInterface
{
    public ?TokenSet $token = null;
    public int $gets = 0;
    public int $puts = 0;
    public int $clears = 0;
    public bool $failReads = false;
    public bool $failWrites = false;

    public function get(): ?TokenSet
    {
        $this->gets++;
        if ($this->failReads) {
            throw new \RuntimeException('db down');
        }
        return $this->token;
    }

    public function put(TokenSet $token): void
    {
        $this->puts++;
        if ($this->failWrites) {
            throw new \RuntimeException('db down');
        }
        $this->token = $token;
    }

    public function clear(): void
    {
        $this->clears++;
        $this->token = null;
    }
}

final class TestLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    public function has(string $level, string $message, array $subset = []): bool
    {
        foreach ($this->records as $r) {
            if ($r['level'] === $level && $r['message'] === $message
                && array_intersect_assoc($subset, $r['context']) == $subset) {
                return true;
            }
        }
        return false;
    }

    public function allText(): string
    {
        return json_encode($this->records);
    }
}

/** Mimics this library's response objects (httpStatus/body). */
final class FakeApiResponse
{
    public function __construct(private int $status, private string $body = '{}')
    {
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }
}

/** Mimics a Guzzle-style exception carrying a response. */
final class FakeApiException extends \RuntimeException
{
    public function __construct(private object $response)
    {
        parent::__construct('Client error');
    }

    public function getResponse(): object
    {
        return $this->response;
    }
}
