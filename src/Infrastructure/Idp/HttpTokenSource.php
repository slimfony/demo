<?php

declare(strict_types=1);

namespace Demo\Infrastructure\Idp;

/**
 * Asks the demo identity provider for a token over HTTPS. The provider's certificate comes from a private CA that
 * PHP trusts through `openssl.cafile`, so the connection is verified like any other.
 */
final class HttpTokenSource implements TokenSource
{
    /** @var \Closure(string): string */
    private readonly \Closure $post;

    /**
     * @param (\Closure(string): string)|null $post returns the response body of a POST to a URL; defaults to a bounded stream request
     */
    public function __construct(
        private readonly string $baseUrl,
        ?\Closure $post = null,
    ) {
        $this->post = $post ?? self::request(...);
    }

    #[\Override]
    public function token(string $kind): string
    {
        $body = json_decode(($this->post)(\sprintf('%s/token?kind=%s', $this->baseUrl, rawurlencode($kind))), true);
        $token = \is_array($body) ? ($body['token'] ?? null) : null;

        return \is_string($token) ? $token : throw new \RuntimeException('The identity provider returned no token.');
    }

    private static function request(string $url): string
    {
        $context = stream_context_create([
            'http' => ['method' => 'POST', 'timeout' => 2.0, 'follow_location' => 0, 'ignore_errors' => true, 'header' => "Content-Length: 0\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context, 0, 4096);

        return false === $body ? throw new \RuntimeException('The identity provider does not answer.') : $body;
    }
}
