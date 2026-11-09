<?php

declare(strict_types=1);

namespace Demo\Camt;

use Slimfony\Core\Exception\SlimfonyException;

/** A statement is not acceptable. The reason is a short code (shown, counted); the message is safe to show to the sender. */
final class StatementRejected extends \DomainException implements SlimfonyException
{
    public const string SCHEMA_INVALID = 'schema_invalid';
    public const string UNSAFE_DOCUMENT = 'unsafe_document';
    public const string TOO_LARGE = 'too_large';
    public const string BALANCE_MISMATCH = 'balance_mismatch';
    public const string UNKNOWN_ACCOUNT = 'unknown_account';
    public const string CURRENCY_MISMATCH = 'currency_mismatch';
    public const string ENTRY_OUTSIDE_PERIOD = 'entry_outside_period';
    public const string DUPLICATE_ENTRY = 'duplicate_entry';
    public const string DUPLICATE_STATEMENT = 'duplicate_statement';
    /** The importer tried the whole batch as often as it may and could not do it (the database kept failing, for example). */
    public const string GAVE_UP = 'gave_up';
    /** Not the statement's own fault: it was fine, but the ZIP was rolled back because another statement in it was not. */
    public const string ROLLED_BACK = 'rolled_back';

    public function __construct(
        public readonly string $reason,
        public readonly string $document,
        string $detail,
        public readonly ?string $statementId = null,
    ) {
        parent::__construct($detail);
    }
}
