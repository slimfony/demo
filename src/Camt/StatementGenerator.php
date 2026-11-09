<?php

declare(strict_types=1);

namespace Demo\Camt;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Makes valid CAMT.053 statements for the demo accounts, and on request some that are wrong in one specific way.
 * The same seed always gives the same files, so a run can be repeated and a test can say exactly what it expects.
 */
final class StatementGenerator
{
    public const int ACCOUNTS = 60;
    public const string PERIOD_FROM = '2026-10-01';
    public const string PERIOD_TO = '2026-10-31';

    /** The ways a statement can be wrong, each caught by a different rule. */
    public const array FAULTS = [
        StatementRejected::SCHEMA_INVALID,
        StatementRejected::BALANCE_MISMATCH,
        StatementRejected::UNKNOWN_ACCOUNT,
        StatementRejected::CURRENCY_MISMATCH,
        StatementRejected::DUPLICATE_ENTRY,
        StatementRejected::ENTRY_OUTSIDE_PERIOD,
    ];

    public static function iban(int $account): string
    {
        return 'HU' . str_pad((string) $account, 26, '0', \STR_PAD_LEFT);
    }

    /**
     * @param int         $statements how many files
     * @param float       $faultRate  chance (0 to 1) that a statement is wrong
     * @param string|null $fault      make every wrong statement wrong in this way (one of FAULTS), else a mix
     *
     * @return array<string, string> file name => XML
     */
    public function files(int $seed, int $statements = 10, float $faultRate = 0.0, ?string $fault = null, int $accounts = self::ACCOUNTS): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $files = [];
        for ($i = 1; $i <= $statements; ++$i) {
            $wrong = $faultRate > 0 && $random->getInt(0, 9999) < (int) ($faultRate * 10_000);
            $kind = $wrong ? ($fault ?? self::FAULTS[$random->getInt(0, \count(self::FAULTS) - 1)]) : null;
            $files[\sprintf('statement-%02d.xml', $i)] = $this->statement($random, \sprintf('S%d-%02d', $seed, $i), $kind, max(1, min(self::ACCOUNTS, $accounts)));
        }

        return $files;
    }

    private function statement(Randomizer $random, string $id, ?string $fault, int $accounts): string
    {
        $account = $random->getInt(1, $accounts);
        $iban = StatementRejected::UNKNOWN_ACCOUNT === $fault ? self::iban(900 + $account) : self::iban($account);
        $currency = StatementRejected::CURRENCY_MISMATCH === $fault ? 'USD' : 'EUR';
        $opening = $random->getInt(50_000, 500_000);

        $entries = [];
        $net = 0;
        for ($n = 1, $count = $random->getInt(3, 8); $n <= $count; ++$n) {
            $cents = $random->getInt(500, 90_000);
            $credit = 0 === $random->getInt(0, 1);
            $net += $credit ? $cents : -$cents;
            $day = StatementRejected::ENTRY_OUTSIDE_PERIOD === $fault && 1 === $n ? '2026-11-15' : \sprintf('2026-10-%02d', $random->getInt(1, 28));
            $other = self::iban($random->getInt(1, $accounts));
            $entries[] = [StatementRejected::DUPLICATE_ENTRY === $fault && 2 === $n ? 'E1' : 'E' . $n, $cents, $credit, $day, $other];
        }
        $closing = $opening + $net + (StatementRejected::BALANCE_MISMATCH === $fault ? 100 : 0);

        $xml = $this->document($id, $iban, $currency, $opening, $closing, $entries);

        return StatementRejected::SCHEMA_INVALID === $fault ? str_replace('<Ntry>', '<Ntry><Unexpected/>', $xml) : $xml;
    }

    /**
     * @param list<array{string, int, bool, string, string}> $entries reference, cents, credit?, date, counterparty
     */
    private function document(string $id, string $iban, string $currency, int $opening, int $closing, array $entries): string
    {
        $balance = static fn(string $code, int $cents): string => \sprintf(
            '<Bal><Tp><CdOrPrtry><Cd>%s</Cd></CdOrPrtry></Tp><Amt Ccy="%s">%s</Amt><CdtDbtInd>%s</CdtDbtInd><Dt><Dt>%s</Dt></Dt></Bal>',
            $code,
            $currency,
            Money::format(abs($cents)),
            $cents < 0 ? 'DBIT' : 'CRDT',
            'OPBD' === $code ? self::PERIOD_FROM : self::PERIOD_TO,
        );

        $items = '';
        foreach ($entries as [$reference, $cents, $credit, $day, $other]) {
            $party = $credit ? 'DbtrAcct' : 'CdtrAcct';
            $items .= \sprintf(
                '<Ntry><NtryRef>%s</NtryRef><Amt Ccy="%s">%s</Amt><CdtDbtInd>%s</CdtDbtInd><BookgDt><Dt>%s</Dt></BookgDt><NtryDtls><TxDtls><RltdPties><%s><Id><IBAN>%s</IBAN></Id></%s></RltdPties><RmtInf><Ustrd>Payment %s</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>',
                $reference,
                $currency,
                Money::format($cents),
                $credit ? 'CRDT' : 'DBIT',
                $day,
                $party,
                $other,
                $party,
                $reference,
            );
        }

        return \sprintf(
            '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><GrpHdr><MsgId>%1$s</MsgId><CreDtTm>2026-11-01T08:00:00</CreDtTm></GrpHdr><Stmt><Id>%1$s</Id><CreDtTm>2026-11-01T08:00:00</CreDtTm><FrToDt><FrDtTm>%2$sT00:00:00</FrDtTm><ToDtTm>%3$sT23:59:59</ToDtTm></FrToDt><Acct><Id><IBAN>%4$s</IBAN></Id><Ccy>%5$s</Ccy></Acct>%6$s%7$s%8$s</Stmt></BkToCstmrStmt></Document>',
            $id,
            self::PERIOD_FROM,
            self::PERIOD_TO,
            $iban,
            $currency,
            $balance('OPBD', $opening),
            $balance('CLBD', $closing),
            $items,
        );
    }

    /**
     * The ZIP of a set of files.
     *
     * @param array<string, string> $files name => XML
     */
    public function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'camt');
        if (false === $path) {
            throw new \RuntimeException('No temporary file available.');
        }

        try {
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::OVERWRITE);
            foreach ($files as $name => $xml) {
                $zip->addFromString($name, $xml);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}
