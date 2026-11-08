<?php

declare(strict_types=1);

namespace Demo\Import;

use Demo\Camt\Money;
use Demo\Camt\Statement;
use Demo\Camt\StatementRejected;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Writes one statement: its header, its entries, the account's balance and the statistics of the accounts it names.
 * The caller owns the transaction, so the statement is booked completely or not at all.
 *
 * The statistics rows are updated in the order the entries appear. Two importers that book statements naming the same
 * accounts in different orders wait for each other's rows, and the database ends one of them with a deadlock:
 * `Retry::transactional()` runs that statement again. Sorting the accounts first would avoid this; it is left out on purpose,
 * because the deadlock and the retry are part of what the demo shows.
 */
final class StatementWriter
{
    private const int ENTRIES_PER_INSERT = 100;

    /**
     * Locks every account row the statements will touch, in one fixed order (sorted by IBAN), before any of them is written.
     * Two transactions that lock the same rows in the same order wait for each other but never deadlock. The strict mode needs
     * this: its transaction holds the rows of a whole ZIP, so without a fixed order nearly every pair of ZIPs would deadlock.
     * The partial mode does not do it, on purpose (see above), so that the deadlocks and the retries are visible.
     *
     * @param list<Statement> $statements
     */
    public function lockInOrder(Connection $connection, array $statements): void
    {
        $ibans = [];
        foreach ($statements as $statement) {
            $ibans[$statement->iban] = true;
            foreach ($statement->entries as $entry) {
                if (null !== $entry->counterparty) {
                    $ibans[$entry->counterparty] = true;
                }
            }
        }
        if ([] === $ibans) {
            return;
        }
        $ibans = array_map(strval(...), array_keys($ibans));
        sort($ibans);

        foreach (['accounts', 'account_stats'] as $table) { // the same order of tables everywhere, too
            $connection->executeQuery("SELECT 1 FROM $table WHERE iban IN (?) ORDER BY iban FOR UPDATE", [$ibans], [ArrayParameterType::STRING])->fetchAllAssociative();
        }
    }

    /**
     * @return int the number of entries booked
     *
     * @throws StatementRejected for the rules that need the database: unknown account, currency, duplicate statement
     */
    public function write(Connection $connection, Statement $statement, string $batchId): int
    {
        $accountCurrency = $connection->fetchOne('SELECT currency FROM accounts WHERE iban = ? FOR UPDATE', [$statement->iban]);
        if (false === $accountCurrency) {
            throw new StatementRejected(StatementRejected::UNKNOWN_ACCOUNT, $statement->file, 'The account is not one of ours.', $statement->id);
        }
        if (!\is_string($accountCurrency) || trim($accountCurrency) !== $statement->currency) {
            throw new StatementRejected(StatementRejected::CURRENCY_MISMATCH, $statement->file, \sprintf('The account is kept in %s, the statement is in %s.', \is_string($accountCurrency) ? trim($accountCurrency) : '?', $statement->currency), $statement->id);
        }

        try {
            $statementPk = $connection->fetchOne(
                'INSERT INTO statements (batch_id, statement_id, iban, currency, period_from, period_to, opening_balance, closing_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$batchId, $statement->id, $statement->iban, $statement->currency, $statement->periodFrom, $statement->periodTo, Money::format($statement->openingCents), Money::format($statement->closingCents)],
            );
        } catch (UniqueConstraintViolationException) {
            throw new StatementRejected(StatementRejected::DUPLICATE_STATEMENT, $statement->file, 'A statement with this id was already imported.', $statement->id);
        }

        foreach (array_chunk($statement->entries, self::ENTRIES_PER_INSERT) as $chunk) {
            $values = [];
            $parameters = [];
            foreach ($chunk as $entry) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push($parameters, $statementPk, $entry->reference, $entry->bookingDate, Money::format($entry->cents), $entry->direction, $entry->counterparty, $entry->remittance);
            }
            $connection->executeStatement('INSERT INTO entries (statement_pk, entry_ref, booking_date, amount, cdt_dbt, counterparty, remittance_info) VALUES ' . implode(', ', $values), $parameters);
        }

        $connection->executeStatement(
            'UPDATE accounts SET booked_balance = booked_balance + ?, last_statement_at = now() WHERE iban = ?',
            [Money::format($statement->entriesCents()), $statement->iban],
        );
        foreach ($statement->entries as $entry) {
            $connection->executeStatement(
                'UPDATE account_stats SET entries_total = entries_total + 1, last_booking_date = GREATEST(last_booking_date, ?::date) WHERE iban = ?',
                [$entry->bookingDate, $entry->counterparty ?? $statement->iban],
            );
        }

        return \count($statement->entries);
    }
}
