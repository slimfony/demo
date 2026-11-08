<?php

declare(strict_types=1);

namespace Demo\Imports;

use Slimfony\Core\Exception\SlimfonyException;

/** The upload is not acceptable. The message is safe to send to the caller; `$reason` is the metric label. */
final class RejectedUpload extends \DomainException implements SlimfonyException
{
    private function __construct(
        public readonly string $reason,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function empty(): self
    {
        return new self('empty', 400, 'The request body is empty. Send the ZIP file as the body.');
    }

    public static function tooLarge(int $limit): self
    {
        return new self('too_large', 413, \sprintf('The ZIP is larger than the %d byte limit.', $limit));
    }

    public static function notZip(): self
    {
        return new self('not_zip', 422, 'The body is not a readable ZIP file.');
    }

    public static function tooManyFiles(int $limit): self
    {
        return new self('too_many_files', 422, \sprintf('The ZIP holds no files or more than %d.', $limit));
    }

    public static function unsafeName(): self
    {
        return new self('unsafe_name', 422, 'Only plain .xml file names are accepted (no folders, no path parts).');
    }

    public static function tooMuchData(int $limit): self
    {
        return new self('too_much_data', 413, \sprintf('The ZIP unpacks to more than %d bytes.', $limit));
    }

    public static function compressionRatio(): self
    {
        return new self('compression_ratio', 422, 'A file in the ZIP is compressed suspiciously well.');
    }
}
