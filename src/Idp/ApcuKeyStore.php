<?php

declare(strict_types=1);

namespace Demo\Idp;

/**
 * The signing key lives in shared memory, so every worker thread signs with the key the JWKS document publishes.
 * A restart creates a new key; the api notices the unknown key id and fetches the document again.
 */
final class ApcuKeyStore
{
    private const string KEY = 'demo.idp.key';

    public function load(): SigningKey
    {
        $stored = apcu_fetch(self::KEY);
        if ($stored instanceof SigningKey) {
            return $stored;
        }

        apcu_add(self::KEY, SigningKey::generate()); // when two threads race, the first wins and both read it back
        $stored = apcu_fetch(self::KEY);

        return $stored instanceof SigningKey ? $stored : throw new \RuntimeException('The signing key could not be stored in APCu.');
    }
}
