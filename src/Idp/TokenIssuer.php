<?php

declare(strict_types=1);

namespace Demo\Idp;

use Firebase\JWT\JWT;

/** Signs demo tokens (EdDSA). The wrong ones are wrong in exactly one way, so each shows one defence of the api. */
final class TokenIssuer
{
    private const int LIFETIME_SECONDS = 300;
    private const string SCOPE = 'import:write import:read';

    /** @var \Closure(): int */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): int)|null $now defaults to time()
     */
    public function __construct(
        private readonly SigningKey $key,
        private readonly string $issuer,
        private readonly string $audience,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? time(...);
    }

    public function issue(TokenKind $kind): string
    {
        $now = ($this->now)();
        $claims = [
            'iss' => $this->issuer,
            'aud' => TokenKind::WrongAudience === $kind ? 'some-other-service' : $this->audience,
            'sub' => 'demo-client',
            'iat' => $now,
            'exp' => TokenKind::Expired === $kind ? $now - 3600 : $now + self::LIFETIME_SECONDS,
            'jti' => bin2hex(random_bytes(8)),
            'scope' => TokenKind::MissingScope === $kind ? 'profile' : self::SCOPE,
        ];
        $secret = TokenKind::BadSignature === $kind ? SigningKey::generate()->secretKey : $this->key->secretKey; // same key id, someone else's key

        return JWT::encode($claims, base64_encode($secret), 'EdDSA', $this->key->id);
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        return ['keys' => [[
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'use' => 'sig',
            'alg' => 'EdDSA',
            'kid' => $this->key->id,
            'x' => rtrim(strtr(base64_encode($this->key->publicKey), '+/', '-_'), '='),
        ]]];
    }
}
