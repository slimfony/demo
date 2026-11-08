<?php

declare(strict_types=1);

namespace Demo\Idp\Controller;

use Demo\Idp\KeySet;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Demo only: lets the page show a key rotation. A real provider rotates on its own schedule, not on request. */
final class RotateController
{
    public function __construct(private readonly KeySet $keys) {}

    public function __invoke(Request $request): Response
    {
        $new = $this->keys->rotate();

        return new JsonResponse(['kid' => $new->id, 'published' => array_map(static fn($key): string => $key->id, $this->keys->all())], headers: ['Cache-Control' => 'no-store']);
    }
}
