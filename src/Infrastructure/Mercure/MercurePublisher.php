<?php

declare(strict_types=1);

namespace Demo\Infrastructure\Mercure;

use Demo\Events\EventPublisher;
use Firebase\JWT\JWT;

/**
 * Publishes events to the Mercure hub that runs inside the web server, so the page can follow them live.
 * The hub only accepts publishers that present a token signed with the shared key (read from a file, never from the repository).
 * An event that cannot be delivered is dropped: what the page shows is never more important than the work itself.
 */
final class MercurePublisher implements EventPublisher
{
    private const int TOKEN_SECONDS = 600;

    private string $token = '';
    private int $tokenExpires = 0;

    public function __construct(
        private readonly string $hubUrl,
        #[\SensitiveParameter]
        private readonly string $key,
        private readonly string $topicPrefix = 'urn:demo:',
    ) {}

    #[\Override]
    public function publish(string $topic, array $data): void
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'timeout' => 0.5,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAuthorization: Bearer " . $this->token() . "\r\n",
            'content' => http_build_query(['topic' => $this->topicPrefix . $topic, 'data' => json_encode($data + ['at' => (int) (microtime(true) * 1000)], \JSON_THROW_ON_ERROR)]),
        ]]);
        @file_get_contents($this->hubUrl, false, $context, 0, 1024);
    }

    private function token(): string
    {
        $now = time();
        if ($now >= $this->tokenExpires - 30) {
            $this->tokenExpires = $now + self::TOKEN_SECONDS;
            $this->token = JWT::encode(['mercure' => ['publish' => ['*']], 'exp' => $this->tokenExpires], $this->key, 'HS256');
        }

        return $this->token;
    }
}
