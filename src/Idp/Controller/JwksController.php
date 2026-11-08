<?php

declare(strict_types=1);

namespace Demo\Idp\Controller;

use Demo\Idp\TokenIssuer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class JwksController
{
    public function __construct(private readonly TokenIssuer $issuer) {}

    public function __invoke(Request $request): Response
    {
        return new JsonResponse($this->issuer->jwks(), headers: ['Cache-Control' => 'public, max-age=60']);
    }
}
