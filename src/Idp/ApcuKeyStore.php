<?php

declare(strict_types=1);

namespace Demo\Idp;

/**
 * The signing keys live in shared memory, so every worker thread signs with the key the JWKS document publishes.
 * A restart creates a new key; the api notices the unknown key id and fetches the document again.
 *
 * A superseded key stays published until every token it signed has expired, however many rotations came in between
 * (so a double click on "rotate" never invalidates a token that is still good). Rotations are at least `MIN_INTERVAL`
 * seconds apart: a request that comes sooner gets the current key and changes nothing. That bounds the number of
 * published keys, and the lock makes two threads that rotate at the same moment take turns.
 */
final class ApcuKeyStore implements KeySet
{
    public const int MIN_INTERVAL_SECONDS = 2;
    /** A token is valid for its lifetime plus the verifier's leeway; the key that signed it stays that long after the next key took over. */
    private const int RETAIN_SECONDS = TokenIssuer::LIFETIME_SECONDS + 60;
    private const string KEY = 'demo.idp.keys';
    private const string LOCK = 'demo.idp.rotating';

    /** @var \Closure(): int */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): int)|null $now defaults to time()
     */
    public function __construct(?\Closure $now = null)
    {
        $this->now = $now ?? time(...);
    }

    #[\Override]
    public function current(): SigningKey
    {
        return $this->all()[0];
    }

    /** @return non-empty-list<SigningKey> */
    #[\Override]
    public function all(): array
    {
        $entries = $this->stored();
        if (null === $entries) {
            apcu_add(self::KEY, [[SigningKey::generate(), ($this->now)()]]); // when two threads race, the first wins and both read it back
            $entries = $this->stored() ?? throw new \RuntimeException('The signing key could not be stored in APCu.');
        }

        return array_map(static fn(array $entry): SigningKey => $entry[0], $this->retained($entries));
    }

    #[\Override]
    public function rotate(): SigningKey
    {
        $this->all(); // there is a first key
        if (!apcu_add(self::LOCK, 1, 5)) {
            return $this->current(); // another thread is rotating right now
        }

        try {
            $entries = $this->stored() ?? throw new \RuntimeException('The signing keys are gone from APCu.');
            $now = ($this->now)();
            if ($now - $entries[0][1] < self::MIN_INTERVAL_SECONDS) {
                return $entries[0][0];
            }
            $key = SigningKey::generate();
            apcu_store(self::KEY, $this->retained([[$key, $now], ...$entries]));

            return $key;
        } finally {
            apcu_delete(self::LOCK);
        }
    }

    /**
     * @param non-empty-list<array{SigningKey, int}> $entries newest first, with the time each key took over
     *
     * @return non-empty-list<array{SigningKey, int}> the newest key and every older one whose successor is younger than RETAIN_SECONDS
     */
    private function retained(array $entries): array
    {
        $now = ($this->now)();
        $kept = [$entries[0]];
        for ($i = 1, $count = \count($entries); $i < $count && $now - $entries[$i - 1][1] < self::RETAIN_SECONDS; ++$i) {
            $kept[] = $entries[$i];
        }

        return $kept;
    }

    /** @return non-empty-list<array{SigningKey, int}>|null */
    private function stored(): ?array
    {
        $stored = apcu_fetch(self::KEY);
        $entries = [];
        foreach (\is_array($stored) ? $stored : [] as $entry) {
            if (\is_array($entry) && ($entry[0] ?? null) instanceof SigningKey && \is_int($entry[1] ?? null)) {
                $entries[] = [$entry[0], $entry[1]];
            }
        }

        return [] === $entries ? null : $entries;
    }
}
