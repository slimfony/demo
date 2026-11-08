<?php

declare(strict_types=1);

namespace Demo\Infrastructure\Idp;

interface TokenSource
{
    /**
     * @throws \RuntimeException when the identity provider does not answer
     */
    public function token(string $kind): string;
}
