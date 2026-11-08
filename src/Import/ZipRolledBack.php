<?php

declare(strict_types=1);

namespace Demo\Import;

use Demo\Camt\StatementRejected;
use Slimfony\Core\Exception\SlimfonyException;

/** Thrown inside the ZIP's transaction in strict mode: it ends the transaction with a rollback of every statement in the ZIP. */
final class ZipRolledBack extends \RuntimeException implements SlimfonyException
{
    /**
     * @param list<StatementRejected> $errors   the statements that were wrong
     * @param list<StatementRejected> $innocent the statements that were fine and are rolled back with the others
     */
    public function __construct(
        public readonly array $errors,
        public readonly array $innocent,
    ) {
        parent::__construct('The ZIP was rolled back.');
    }
}
