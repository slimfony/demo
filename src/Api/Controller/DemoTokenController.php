<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Idp\TokenKind;
use Demo\Infrastructure\Idp\TokenSource;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Http\ProblemResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hands the page a token from the demo identity provider, a good one or a deliberately broken one, so a visitor can
 * watch the api accept and refuse them. A real service would never give tokens away like this.
 */
final class DemoTokenController
{
    public function __construct(private readonly TokenSource $tokens) {}

    public function __invoke(Request $request): Response
    {
        $requestId = $request->attributes->get(Attributes::REQUEST_ID);
        $requestId = \is_string($requestId) ? $requestId : null;

        $kind = TokenKind::tryFrom($request->query->getString('kind', 'valid'));
        if (null === $kind) {
            return new ProblemResponse(400, $requestId, 'Unknown token kind.');
        }

        try {
            $token = $this->tokens->token($kind->value);
        } catch (\RuntimeException) {
            return new ProblemResponse(503, $requestId, 'The identity provider does not answer.');
        }

        return new JsonResponse(['token' => $token, 'kind' => $kind->value], headers: ['Cache-Control' => 'no-store']);
    }
}
