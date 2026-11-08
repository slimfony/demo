<?php

declare(strict_types=1);

namespace Demo\Camt;

/** One account statement read from a CAMT.053 file. */
final readonly class Statement
{
    /**
     * @param list<Entry> $entries
     * @param int         $openingCents signed: money in the account counts positive
     * @param int         $closingCents signed
     */
    public function __construct(
        public string $file,
        public string $id,
        public string $iban,
        public string $currency,
        public string $periodFrom,
        public string $periodTo,
        public int $openingCents,
        public int $closingCents,
        public array $entries,
    ) {}

    public function entriesCents(): int
    {
        return array_sum(array_map(static fn(Entry $entry): int => $entry->signedCents(), $this->entries));
    }
}
