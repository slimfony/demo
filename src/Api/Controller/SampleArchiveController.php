<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Camt\StatementGenerator;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Http\ProblemResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A ZIP of generated CAMT.053 statements that the page (or a script) can upload, so nobody needs a file of their own.
 * The same seed gives the same ZIP. Optionally some statements are wrong: `fault_rate` (0 to 1) and, to make them all
 * wrong in one way, `fault` (one of the codes the importer reports).
 */
final class SampleArchiveController
{
    private const int MAX_FILES = 10;

    public function __construct(private readonly StatementGenerator $generator) {}

    public function __invoke(Request $request): Response
    {
        $files = max(1, min(self::MAX_FILES, $request->query->getInt('files', 10)));
        $seed = $request->query->getInt('seed', random_int(1, 1_000_000_000));
        $rate = max(0.0, min(1.0, (float) $request->query->getString('fault_rate', '0')));
        $fault = $request->query->getString('fault');
        if ('' !== $fault && !\in_array($fault, StatementGenerator::FAULTS, true)) {
            $id = $request->attributes->get(Attributes::REQUEST_ID);

            return new ProblemResponse(400, \is_string($id) ? $id : null, 'Unknown fault.');
        }

        $zip = $this->generator->zip($this->generator->files($seed, $files, $rate, '' === $fault ? null : $fault, $request->query->getInt('accounts', StatementGenerator::ACCOUNTS)));

        return new Response($zip, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="statements.zip"', 'Cache-Control' => 'no-store']);
    }
}
