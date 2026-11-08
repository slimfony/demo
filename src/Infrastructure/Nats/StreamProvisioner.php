<?php

declare(strict_types=1);

namespace Demo\Infrastructure\Nats;

use Basis\Nats\Client;
use Basis\Nats\Stream\StorageBackend;
use Basis\Nats\Stream\Stream;

/** Makes sure the JetStream stream exists. The relay and every importer call it at start-up, whoever starts first creates it. */
final class StreamProvisioner
{
    public const string STREAM = 'IMPORTS';
    public const string SUBJECT = 'imports.>';
    public const string ACCEPTED = 'imports.accepted';

    public function ensure(Client $client, int $maxAgeSeconds = 3600): Stream
    {
        $stream = $client->getApi()->getStream(self::STREAM);
        $stream->getConfiguration()
            ->setSubjects([self::SUBJECT])
            ->setStorageBackend(StorageBackend::FILE)
            ->setMaxAge($maxAgeSeconds * 1_000_000_000);
        $stream->createIfNotExists();

        return $stream;
    }
}
