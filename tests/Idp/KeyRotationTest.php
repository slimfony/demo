<?php

declare(strict_types=1);

namespace Demo\Tests\Idp;

use Demo\Idp\ApcuKeyStore;
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

    private int $clock = 1_000_000;

    protected function setUp(): void
    {
        if (!\function_exists('apcu_enabled') || !apcu_enabled()) {
            self::markTestSkipped('APCu is not enabled for the CLI (apc.enable_cli=1).');
        }
        apcu_clear_cache();
    }

    private function issuer(): TokenIssuer
    {
        return new TokenIssuer(new ApcuKeyStore(fn(): int => $this->clock), 'https://idp.test', 'demo-api');
    }

    public function testASignedTokenStaysGoodThroughRotationsAndTheOldKeyGoesWhenTheTokenHasExpired(): void
    {
        $issuer = $this->issuer();
        $beforeRotation = $issuer->issue(TokenKind::Valid);
        $firstKid = $issuer->jwks()['keys'][0]['kid'];
        self::assertTrue($this->accepted($issuer, $beforeRotation));

        $this->clock += 10;
        $issuer->rotate();
        $this->clock += 10;
        $issuer->rotate();
        $this->clock += 10;
        $issuer->rotate();
        self::assertContains($firstKid, array_column($issuer->jwks()['keys'], 'kid'));
        self::assertTrue($this->accepted($issuer, $beforeRotation), 'a token signed before the rotations is still good, however many there were');
        self::assertTrue($this->accepted($issuer, $issuer->issue(TokenKind::Valid)), 'and so is one signed with the new key');

        $this->clock += 400; // longer than a token lives
        self::assertNotContains($firstKid, array_column($issuer->jwks()['keys'], 'kid'));
    }

    public function testRotationsCloserThanTheMinimumIntervalAreOne(): void
    {
        $issuer = $this->issuer();
        $beforeRotation = $issuer->issue(TokenKind::Valid);
        $this->clock += 10;
        $first = $issuer->rotate();

        $second = $issuer->rotate(); // the double click
        $this->clock += ApcuKeyStore::MIN_INTERVAL_SECONDS - 1;
        $third = $issuer->rotate();

        self::assertSame($first->id, $second->id);
        self::assertSame($first->id, $third->id);
        self::assertCount(2, $issuer->jwks()['keys'], 'the key that signed the earlier token and the new one');
        self::assertTrue($this->accepted($issuer, $beforeRotation));
    }
}
