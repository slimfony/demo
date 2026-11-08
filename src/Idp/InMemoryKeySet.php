<?php

declare(strict_types=1);

namespace Demo\Idp;

final class InMemoryKeySet implements KeySet
{
    private const int KEPT = 2;

    /** @var non-empty-list<SigningKey> */
    private array $keys;

    public function __construct(SigningKey $first)
    {
        $this->keys = [$first];
    }

    #[\Override]
    public function current(): SigningKey
    {
        return $this->keys[0];
    }

    /** @return non-empty-list<SigningKey> */
    #[\Override]
    public function all(): array
    {
        return $this->keys;
    }

    #[\Override]
    public function rotate(): SigningKey
    {
        $key = SigningKey::generate();
        $this->keys = [$key, ...\array_slice($this->keys, 0, self::KEPT - 1)];

        return $key;
    }
}
