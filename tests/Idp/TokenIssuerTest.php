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
use Slimfony\Core\Auth\Principal;
use Symfony\Component\HttpFoundation\Request;

/** The demo tokens against the real verifier of slimfony/core: the good one passes, each broken one fails for its own reason. */
final class TokenIssuerTest extends TestCase
{
    private TokenIssuer $issuer;
    private JwtAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->issuer = new TokenIssuer(new InMemoryKeySet(SigningKey::generate()), 'https://idp.test', 'demo-api');
        // the verifier learns the key from the published JWKS document, exactly as the api does
        $keys = JWK::parseKeySet($this->issuer->jwks(), 'EdDSA');
        $this->authenticator = new JwtAuthenticator(
            new JwtConfiguration('https://idp.test', 'demo-api', ['EdDSA']),
            new StaticKeyProvider($keys),
            cacheSeconds: 0,
        );
    }

    private function authenticate(TokenKind $kind): ?Principal
    {
        return $this->authenticator->authenticate(Request::create('/', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->issuer->issue($kind)]));
    }

    public function testTheValidTokenCarriesTheImportScopes(): void
    {
        $principal = $this->authenticate(TokenKind::Valid);

        self::assertNotNull($principal);
        self::assertSame('demo-client', $principal->subject);
        self::assertTrue($principal->hasScope('import:write'));
        self::assertTrue($principal->hasScope('import:read'));
    }

    public function testEveryBrokenTokenIsRefusedByTheVerifierOrLacksTheScope(): void
    {
        self::assertNull($this->authenticate(TokenKind::Expired));
        self::assertNull($this->authenticate(TokenKind::BadSignature));
        self::assertNull($this->authenticate(TokenKind::WrongAudience));

        $limited = $this->authenticate(TokenKind::MissingScope); // a valid token, but not for this: the middleware answers 403
        self::assertNotNull($limited);
        self::assertFalse($limited->hasScope('import:write'));
    }
}
