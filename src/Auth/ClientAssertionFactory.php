<?php

namespace Shopify\Auth;

/**
 * Builds the signed JWT ("client assertion") for one application-only token request.
 * Every call returns a new assertion with a new jti: Shopify accepts each jti once per app.
 */
final class ClientAssertionFactory
{
    public const TTL = 60;

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly string $clientId,
        private readonly string $kid,
        private readonly AssertionSignerInterface $signer,
        ?callable $clock = null,
    ) {
        if ($clientId === '' || $kid === '') {
            throw new \InvalidArgumentException('Client ID and kid are required');
        }
        $this->clock = $clock ?? 'time';
    }

    public function kid(): string
    {
        return $this->kid;
    }

    public function create(string $shop): string
    {
        $iat = (int) ($this->clock)();
        $header = ['alg' => 'RS256', 'kid' => $this->kid];
        $claims = [
            'iss' => $this->clientId,
            'sub' => $this->clientId,
            'aud' => 'https://' . $shop . '/admin/oauth/access_token',
            'iat' => $iat,
            'exp' => $iat + self::TTL,
            'jti' => self::uuid4(),
        ];
        $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $input = self::b64u(json_encode($header, $flags)) . '.' . self::b64u(json_encode($claims, $flags));
        return $input . '.' . self::b64u($this->signer->sign($input));
    }

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
