<?php

declare(strict_types=1);

namespace Demo\Tests\Idp;

use Demo\Idp\InMemoryKeySet;
use Demo\Idp\SigningKey;
use Demo\Idp\TokenIssuer;
use Demo\Idp\TokenKind;
use Firebase\JWT\JWK;
use PHPUnit\Framework\TestCase;
use Slimfony\Core\Auth\Jwt\JwtAuthenticator;
use Slimfony\Core\Auth\Jwt\JwtConfiguration;
use Slimfony\Core\Auth\Jwt\StaticKeyProvider;
use Symfony\Component\HttpFoundation\Request;

/** A key rotation: the new key signs, the old key stays published for the tokens it already signed, and then it is dropped. */
final class KeyRotationTest extends TestCase
{
    private function accepted(TokenIssuer $issuer, string $token): bool
    {
        // the verifier is built from the key document as it is published right now
        $verifier = new JwtAuthenticator(
            new JwtConfiguration('https://idp.test', 'demo-api', ['EdDSA']),
            new StaticKeyProvider(JWK::parseKeySet($issuer->jwks(), 'EdDSA')),
            cacheSeconds: 0,
        );

        return null !== $verifier->authenticate(Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]));
    }

    public function testTheOldKeyStaysPublishedForOneRotationAndThenGoes(): void
    {
        $issuer = new TokenIssuer(new InMemoryKeySet(SigningKey::generate()), 'https://idp.test', 'demo-api');
        $beforeRotation = $issuer->issue(TokenKind::Valid);
        self::assertTrue($this->accepted($issuer, $beforeRotation));

        $issuer->rotate();
        self::assertCount(2, $issuer->jwks()['keys']);
        self::assertTrue($this->accepted($issuer, $beforeRotation), 'a token signed before the rotation is still good');
        self::assertTrue($this->accepted($issuer, $issuer->issue(TokenKind::Valid)), 'and so is one signed with the new key');

        $issuer->rotate();
        self::assertCount(2, $issuer->jwks()['keys']);
        self::assertFalse($this->accepted($issuer, $beforeRotation), 'two rotations later the first key is gone');
    }
}
