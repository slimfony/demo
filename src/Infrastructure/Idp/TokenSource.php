<?php

declare(strict_types=1);

namespace Demo\Infrastructure\Idp;

interface TokenSource
{
    /**
     * @throws \RuntimeException when the identity provider does not answer
     */
    public function token(string $kind): string;

    /**
     * Asks the provider to start signing with a new key.
     *
     * @return string the id of the new key
     *
     * @throws \RuntimeException when the identity provider does not answer
     */
    public function rotate(): string;
}
