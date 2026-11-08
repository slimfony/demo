<?php

declare(strict_types=1);

namespace Demo\Idp;

/** The identity provider's signing keys: the newest signs, the older ones stay published until the tokens they signed have expired. */
interface KeySet
{
    public function current(): SigningKey;

    /** @return non-empty-list<SigningKey> newest first */
    public function all(): array;

    /** Makes a new key the current one and returns it. */
    public function rotate(): SigningKey;
}
