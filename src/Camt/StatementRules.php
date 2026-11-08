<?php

declare(strict_types=1);

namespace Demo\Camt;

/** The business rules that need nothing but the statement itself (the ones that need the database are checked when it is written). */
final class StatementRules
{
    /**
     * @throws StatementRejected
     */
    public function check(Statement $statement): void
    {
        $expected = $statement->openingCents + $statement->entriesCents();
        if ($expected !== $statement->closingCents) {
            throw new StatementRejected(
                StatementRejected::BALANCE_MISMATCH,
                $statement->file,
                \sprintf('Opening %s plus the entries gives %s, but the closing balance says %s.', Money::format($statement->openingCents), Money::format($expected), Money::format($statement->closingCents)),
                $statement->id,
            );
        }

        $from = substr($statement->periodFrom, 0, 10);
        $to = substr($statement->periodTo, 0, 10);
        $seen = [];
        foreach ($statement->entries as $entry) {
            if (isset($seen[$entry->reference])) {
                throw new StatementRejected(StatementRejected::DUPLICATE_ENTRY, $statement->file, \sprintf('Entry reference %s appears twice.', $entry->reference), $statement->id);
            }
            $seen[$entry->reference] = true;

            if ($entry->bookingDate < $from || $entry->bookingDate > $to) {
                throw new StatementRejected(StatementRejected::ENTRY_OUTSIDE_PERIOD, $statement->file, \sprintf('Entry %s is booked on %s, outside %s to %s.', $entry->reference, $entry->bookingDate, $from, $to), $statement->id);
            }
        }
    }
}
