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
        private readonly KeySet $keys,
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
        $key = $this->keys->current();
        $secret = TokenKind::BadSignature === $kind ? SigningKey::generate()->secretKey : $key->secretKey; // same key id, someone else's key

        return JWT::encode($claims, base64_encode($secret), 'EdDSA', $key->id);
    }

    /** Starts signing with a new key; the previous one stays published for the tokens it signed. */
    public function rotate(): SigningKey
    {
        return $this->keys->rotate();
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        return ['keys' => array_map(static fn(SigningKey $key): array => [
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'use' => 'sig',
            'alg' => 'EdDSA',
            'kid' => $key->id,
            'x' => rtrim(strtr(base64_encode($key->publicKey), '+/', '-_'), '='),
        ], $this->keys->all())];
    }
}
