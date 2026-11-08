<?php

declare(strict_types=1);

namespace Demo\Idp;

/** The tokens the demo identity provider hands out: one good one and the four ways a token goes wrong. */
enum TokenKind: string
{
    case Valid = 'valid';
    case Expired = 'expired';
    case BadSignature = 'bad_signature';
    case WrongAudience = 'wrong_audience';
    case MissingScope = 'missing_scope';
}
