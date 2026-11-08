<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Infrastructure\Idp\TokenSource;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Http\ProblemResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the page rotate the identity provider's signing key. The next token carries a key id the api has never seen:
 * it fetches the provider's key document again (at most once every two seconds) and goes on.
 */
final class DemoRotateController
{
    public function __construct(private readonly TokenSource $tokens) {}

    public function __invoke(Request $request): Response
    {
        try {
            return new JsonResponse(['kid' => $this->tokens->rotate()], headers: ['Cache-Control' => 'no-store']);
        } catch (\RuntimeException) {
            $id = $request->attributes->get(Attributes::REQUEST_ID);

            return new ProblemResponse(503, \is_string($id) ? $id : null, 'The identity provider does not answer.');
        }
    }
}
