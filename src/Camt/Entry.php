<?php

declare(strict_types=1);

namespace Demo\Camt;

final readonly class Entry
{
    public const string CREDIT = 'CRDT';
    public const string DEBIT = 'DBIT';

    public function __construct(
        public string $reference,
        public int $cents,
        public string $direction,
        public string $bookingDate,
        public ?string $counterparty,
        public ?string $remittance,
    ) {}

    /** Positive for money in, negative for money out. */
    public function signedCents(): int
    {
        return self::CREDIT === $this->direction ? $this->cents : -$this->cents;
    }
}
