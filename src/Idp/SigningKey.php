<?php

declare(strict_types=1);

namespace Demo\Idp;

/** An Ed25519 key pair and its id. */
final readonly class SigningKey
{
    public function __construct(
        public string $id,
        #[\SensitiveParameter]
        public string $secretKey,
        public string $publicKey,
    ) {}

    public static function generate(): self
    {
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);

        return new self(substr(hash('sha256', $public), 0, 16), sodium_crypto_sign_secretkey($pair), $public);
    }
}
