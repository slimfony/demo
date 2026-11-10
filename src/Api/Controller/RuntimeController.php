<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Composer\InstalledVersions;
use Demo\Api\ProcessMemory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Facts about the runtime that answered the request, read from the real settings (nothing is hard-coded).
 */
final class RuntimeController
{
    public function __construct(
        private readonly ProcessMemory $memory = new ProcessMemory(),
        private readonly string $versionFile = __DIR__ . '/../../../VERSION',
    ) {}

    public function __invoke(Request $request): Response
    {
        $opcache = \function_exists('opcache_get_status') ? opcache_get_status(false) : false;
        $jit = \is_array($opcache) && \is_array($opcache['jit'] ?? null) && true === ($opcache['jit']['on'] ?? false);
        $frankenphp = getenv('FRANKENPHP_VERSION');
        $started = apcu_fetch('demo.started');

        return new JsonResponse([
            'php' => \PHP_VERSION,
            'worker_mode' => \function_exists('frankenphp_handle_request'),
            'frankenphp' => false === $frankenphp ? null : $frankenphp,
            'opcache' => \is_array($opcache) && true === ($opcache['opcache_enabled'] ?? false),
            'jit' => $jit,
            'core' => InstalledVersions::getPrettyVersion('slimfony/core'),
            'app_version' => $this->version(),
            'memory_bytes' => memory_get_usage(true), // the heap of the PHP thread that answered
            'server_memory_bytes' => $this->memory->resident(), // the whole server process
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'uptime_seconds' => \is_int($started) ? max(0, time() - $started) : 0,
        ]);
    }

    /**
     * The version this image was built as: the `APP_VERSION` it was given, else the one the build read from the changelog (the
     * file VERSION: the latest release, with `+dev` when there are unreleased changes); `dev` outside an image.
     */
    private function version(): string
    {
        $given = getenv('APP_VERSION');
        if (\is_string($given) && '' !== $given) {
            return $given;
        }
        $read = is_readable($this->versionFile) ? trim((string) file_get_contents($this->versionFile)) : '';

        return '' !== $read ? $read : 'dev';
    }
}
