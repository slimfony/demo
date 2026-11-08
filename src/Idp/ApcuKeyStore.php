<?php

declare(strict_types=1);

namespace Demo\Idp;

/**
 * The signing keys live in shared memory, so every worker thread signs with the key the JWKS document publishes.
 * A restart creates a new key; the api notices the unknown key id and fetches the document again.
 */
final class ApcuKeyStore implements KeySet
{
    private const string KEY = 'demo.idp.keys';
    private const int KEPT = 2; // the newest, and the one before it: tokens signed with it stay valid until they expire (five minutes)

    #[\Override]
    public function current(): SigningKey
    {
        return $this->all()[0];
    }

    /** @return non-empty-list<SigningKey> */
    #[\Override]
    public function all(): array
    {
        $keys = $this->stored();
        if (null !== $keys) {
            return $keys;
        }

        apcu_add(self::KEY, [SigningKey::generate()]); // when two threads race, the first wins and both read it back

        return $this->stored() ?? throw new \RuntimeException('The signing key could not be stored in APCu.');
    }

    #[\Override]
    public function rotate(): SigningKey
    {
        $key = SigningKey::generate();
        apcu_store(self::KEY, [$key, ...\array_slice($this->all(), 0, self::KEPT - 1)]);

        return $key;
    }

    /** @return non-empty-list<SigningKey>|null */
    private function stored(): ?array
    {
        $stored = apcu_fetch(self::KEY);
        $keys = [];
        foreach (\is_array($stored) ? $stored : [] as $key) {
            if ($key instanceof SigningKey) {
                $keys[] = $key;
            }
        }

        return [] === $keys ? null : $keys;
    }
}
