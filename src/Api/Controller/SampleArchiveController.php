<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** A small ZIP the page can upload, so a visitor can try the upload routes without a file of their own. */
final class SampleArchiveController
{
    private const int MAX_FILES = 10;

    public function __invoke(Request $request): Response
    {
        $files = max(1, min(self::MAX_FILES, $request->query->getInt('files', 3)));

        $path = tempnam(sys_get_temp_dir(), 'sample');
        if (false === $path) {
            throw new \RuntimeException('No temporary file available.');
        }

        try {
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::OVERWRITE);
            for ($i = 1; $i <= $files; ++$i) {
                $zip->addFromString(\sprintf('statement-%02d.xml', $i), \sprintf('<Document><BkToCstmrStmt><Stmt><Id>sample-%02d</Id></Stmt></BkToCstmrStmt></Document>', $i));
            }
            $zip->close();
            $bytes = (string) file_get_contents($path);
        } finally {
            unlink($path);
        }

        return new Response($bytes, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="sample.zip"', 'Cache-Control' => 'no-store']);
    }
}
