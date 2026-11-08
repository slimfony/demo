<?php

declare(strict_types=1);

namespace Demo\Idp\Controller;

use Demo\Idp\TokenIssuer;
use Demo\Idp\TokenKind;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Http\ProblemResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Demo only: a real identity provider authenticates the client first. Here anybody on the internal network may ask. */
final class TokenController
{
    public function __construct(private readonly TokenIssuer $issuer) {}

    public function __invoke(Request $request): Response
    {
        $kind = TokenKind::tryFrom($request->query->getString('kind', 'valid'));
        if (null === $kind) {
            $id = $request->attributes->get(Attributes::REQUEST_ID);

            return new ProblemResponse(400, \is_string($id) ? $id : null, 'Unknown token kind.');
        }

        return new JsonResponse(['token' => $this->issuer->issue($kind), 'kind' => $kind->value], headers: ['Cache-Control' => 'no-store']);
    }
}
