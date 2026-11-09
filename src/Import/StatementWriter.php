<?php

declare(strict_types=1);

namespace Demo\Import;

use Demo\Camt\Money;
use Demo\Camt\Statement;
use Demo\Camt\StatementRejected;
use Demo\Imports\LockOrder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Writes one statement: its header, its entries, the account's balance and the statistics of the accounts it names.
 * The caller owns the transaction, so the statement is booked completely or not at all.
 *
 * Every statement updates the rows of a few shared accounts, so importers meet on the same rows. How they meet is the
 * lock order:
 *
 * - `LockOrder::Sorted` (the default): the rows are locked first, all of them, sorted by IBAN. Transactions that lock the
 *   same rows in the same order wait for each other but never deadlock. The statistics are then updated in one statement.
 * - `LockOrder::Appearance`: the statistics rows are updated one entry at a time, in the order the entries appear. Two
 *   importers that book statements naming the same accounts in different orders wait for each other's rows, and the
 *   database ends one of them with a deadlock; `Retry::transactional()` runs it again. It is slower, and it is what makes
 *   the deadlock and the retry visible, so the page offers it as a choice.
 */
final class StatementWriter
{
    private const int ENTRIES_PER_INSERT = 100;

    /**
     * Locks every row the statements will touch, in one fixed order (accounts first, then statistics, each sorted by IBAN),
     * before any of them is written. The strict mode calls it once for the whole ZIP.
     *
     * @param list<Statement> $statements
     *
     * @return array<string, string> the currency of each locked account, by IBAN (the lock query returns it, so it costs no extra round trip)
     */
    public function lockInOrder(Connection $connection, array $statements): array
    {
        $accounts = [];
        $statistics = [];
        foreach ($statements as $statement) {
            $accounts[$statement->iban] = true;
            $statistics[$statement->iban] = true;
            foreach ($statement->entries as $entry) {
                if (null !== $entry->counterparty) {
                    $statistics[$entry->counterparty] = true;
                }
            }
        }

        $currencies = [];
        foreach (['accounts' => $accounts, 'account_stats' => $statistics] as $table => $ibans) { // the same order of tables everywhere, too
            if ([] === $ibans) {
                continue;
            }
            $sorted = array_map(strval(...), array_keys($ibans));
            sort($sorted);
            $column = 'accounts' === $table ? 'currency' : '1';
            foreach ($connection->executeQuery("SELECT iban, $column AS currency FROM $table WHERE iban IN (?) ORDER BY iban FOR UPDATE", [$sorted], [ArrayParameterType::STRING])->fetchAllAssociative() as $row) {
                if ('accounts' === $table && \is_string($row['iban']) && \is_string($row['currency'])) {
                    $currencies[$row['iban']] = $row['currency'];
                }
            }
        }

        return $currencies;
    }

    /**
     * @param bool $locked the caller has locked the rows already (the strict mode does it for the whole ZIP)
     *
     * @return int the number of entries booked
     *
     * @throws StatementRejected for the rules that need the database: unknown account, currency, duplicate statement
     */
    public function write(Connection $connection, Statement $statement, string $batchId, LockOrder $order = LockOrder::Sorted, bool $locked = false): int
    {
        if (LockOrder::Sorted === $order && !$locked) {
            $accountCurrency = $this->lockInOrder($connection, [$statement])[$statement->iban] ?? false;
        } else {
            $accountCurrency = $connection->fetchOne('SELECT currency FROM accounts WHERE iban = ? FOR UPDATE', [$statement->iban]);
        }
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
        LockOrder::Sorted === $order ? $this->updateStatisticsTogether($connection, $statement) : $this->updateStatisticsOneByOne($connection, $statement);

        return \count($statement->entries);
    }

    /** The rows are locked already (in order): one statement updates them all, one count per account. */
    private function updateStatisticsTogether(Connection $connection, Statement $statement): void
    {
        $perAccount = [];
        foreach ($statement->entries as $entry) {
            $iban = $entry->counterparty ?? $statement->iban;
            $perAccount[$iban] = [($perAccount[$iban][0] ?? 0) + 1, max($perAccount[$iban][1] ?? '', $entry->bookingDate)];
        }
        if ([] === $perAccount) {
            return;
        }

        $values = [];
        $parameters = [];
        foreach ($perAccount as $iban => [$count, $date]) {
            $values[] = '(?::text, ?::int, ?::date)';
            array_push($parameters, $iban, $count, $date);
        }
        $connection->executeStatement(
            'UPDATE account_stats s SET entries_total = s.entries_total + v.n, last_booking_date = GREATEST(s.last_booking_date, v.d)
             FROM (VALUES ' . implode(', ', $values) . ') AS v(iban, n, d) WHERE s.iban = v.iban',
            $parameters,
        );
    }

    /** One update per entry, in the order the entries appear: rows are locked as they are reached, in whatever order that is. */
    private function updateStatisticsOneByOne(Connection $connection, Statement $statement): void
    {
        foreach ($statement->entries as $entry) {
            $connection->executeStatement(
                'UPDATE account_stats SET entries_total = entries_total + 1, last_booking_date = GREATEST(last_booking_date, ?::date) WHERE iban = ?',
                [$entry->bookingDate, $entry->counterparty ?? $statement->iban],
            );
        }
    }
}
