<?php

declare(strict_types=1);

namespace Demo\Api\Controller;

use Demo\Imports\ArchiveInspector;
use Demo\Imports\BatchRepository;
use Demo\Imports\ImportMode;
use Demo\Imports\ImportSource;
use Demo\Imports\LockOrder;
use Demo\Imports\RateLimiter;
use Demo\Imports\RejectedUpload;
use Doctrine\DBAL\Connection;
use Slimfony\Core\Auth\Principal;
use Slimfony\Core\Database\Retry;
use Slimfony\Core\Http\Attributes;
use Slimfony\Core\Http\ProblemResponse;
use Slimfony\Core\Observability\Counter;
use Slimfony\Core\Observability\MetricsRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes a ZIP of bank statements. Two routes lead here: `/api/imports` (a verified token is required, so the
 * caller is known) and `/public/drop` (anonymous sandbox, tighter limits). The route says which one it is.
 * The upload is stored and announced in one transaction; the import itself happens later, in an importer.
 */
final class UploadController
{
    private readonly Counter $accepted;
    private readonly Counter $rejected;

    public function __construct(
        private readonly ArchiveInspector $inspector,
        private readonly BatchRepository $batches,
        private readonly Retry $retry,
        private readonly RateLimiter $sandboxLimit,
        MetricsRegistry $metrics,
    ) {
        $this->accepted = $metrics->counter('imports_accepted_total', 'Uploads that were stored.', ['source']);
        $this->rejected = $metrics->counter('imports_rejected_total', 'Uploads that were refused.', ['source', 'reason']);
    }

    public function __invoke(Request $request): Response
    {
        $requestId = $request->attributes->get(Attributes::REQUEST_ID);
        $requestId = \is_string($requestId) ? $requestId : null;

        $source = ImportSource::tryFrom($request->attributes->getString('_source')) ?? throw new \LogicException('The route does not say where the upload comes from.');
        $mode = ImportMode::tryFrom($request->query->getString('mode', ImportMode::Partial->value));
        if (null === $mode) {
            return new ProblemResponse(400, $requestId, 'The mode must be "partial" or "strict".');
        }
        $lockOrder = LockOrder::tryFrom($request->query->getString('lock_order', LockOrder::Sorted->value));
        if (null === $lockOrder) {
            return new ProblemResponse(400, $requestId, 'The lock order must be "sorted" or "appearance".');
        }

        // the anonymous sandbox is rationed per client; a caller with a verified token is not
        $wait = ImportSource::Public === $source ? $this->sandboxLimit->retryAfter($request->getClientIp() ?? 'unknown') : null;
        if (null !== $wait) {
            $this->rejected->inc([$source->value, 'rate_limited']);

            return new ProblemResponse(429, $requestId, 'Too many uploads to the sandbox. Wait a moment, or use a token.', ['Retry-After' => (string) $wait]);
        }

        try {
            $archive = $this->inspector->inspect($request->getContent(), $source->limits());
        } catch (RejectedUpload $e) {
            $this->rejected->inc([$source->value, $e->reason]);

            return new ProblemResponse($e->status, $requestId, $e->getMessage());
        }

        $subject = null;
        $keyId = null;
        if (ImportSource::Jwt === $source) {
            $subject = Principal::from($request)->subject; // the middleware verified the token before we got here
            $keyId = $this->keyId($request);
        }

        $id = $this->uuid();
        $this->retry->transactional(function (Connection $connection) use ($id, $archive, $mode, $source, $subject, $keyId, $lockOrder): void {
            $this->batches->insertAccepted($connection, $id, $archive, $mode, $source, $subject, $keyId, $lockOrder);
        });
        $this->accepted->inc([$source->value]);

        return new JsonResponse(
            ['id' => $id, 'status' => 'queued', 'source' => $source->value, 'mode' => $mode->value, 'files' => $archive->files, 'sha256' => $archive->sha256],
            202,
            ['Location' => '/api/batches/' . $id],
        );
    }

    /** The key id from the header of the (already verified) bearer token, to record which key vouched for the upload. */
    private function keyId(Request $request): ?string
    {
        $token = substr((string) $request->headers->get('Authorization'), 7);
        $header = json_decode((string) base64_decode(strtr(explode('.', $token, 2)[0], '-_', '+/'), true), true);
        $kid = \is_array($header) ? ($header['kid'] ?? null) : null;

        return \is_string($kid) ? substr($kid, 0, 64) : null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
