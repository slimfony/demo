<?php

declare(strict_types=1);

namespace Demo\Tests\Camt;

use Demo\Camt\CamtParser;
use Demo\Camt\Money;
use Demo\Camt\StatementGenerator;
use Demo\Camt\StatementRejected;
use Demo\Camt\StatementRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CamtTest extends TestCase
{
    private function parser(): CamtParser
    {
        return new CamtParser(__DIR__ . '/../../schema/camt.053.001.02.xsd');
    }

    private function accept(string $xml): void
    {
        $this->parserAndRules($xml);
    }

    private function parserAndRules(string $xml): void
    {
        new StatementRules()->check($this->parser()->parse($xml, 'test.xml'));
    }

    public function testMoneyIsWholeCentsBothWays(): void
    {
        self::assertSame(123_405, Money::cents('1234.05'));
        self::assertSame(1_230, Money::cents('12.3'));
        self::assertSame('-12.05', Money::format(-1205));
        self::assertSame('0.07', Money::format(7));
    }

    public function testTheGeneratorIsRepeatable(): void
    {
        $generator = new StatementGenerator();

        self::assertSame($generator->files(42, 10, 0.3), $generator->files(42, 10, 0.3));
        self::assertNotSame($generator->files(42), $generator->files(43));
    }

    public function testEveryGeneratedGoodStatementPassesTheSchemaAndTheRules(): void
    {
        foreach (new StatementGenerator()->files(7, 30) as $xml) {
            $this->accept($xml);
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function faults(): iterable
    {
        // unknown_account and currency_mismatch need the database: they are checked when the statement is written
        foreach ([StatementRejected::SCHEMA_INVALID, StatementRejected::BALANCE_MISMATCH, StatementRejected::DUPLICATE_ENTRY, StatementRejected::ENTRY_OUTSIDE_PERIOD] as $fault) {
            yield $fault => [$fault];
        }
    }

    #[DataProvider('faults')]
    public function testEachFaultIsCaughtByItsOwnRule(string $fault): void
    {
        $xml = array_values(new StatementGenerator()->files(1, 1, 1.0, $fault))[0];

        try {
            $this->accept($xml);
            self::fail('The statement should have been rejected.');
        } catch (StatementRejected $e) {
            self::assertSame($fault, $e->reason);
        }
    }

    public function testASchemaErrorPointsAtTheLine(): void
    {
        $xml = str_replace('<Ntry>', "\n<Ntry><Unexpected/>", array_values(new StatementGenerator()->files(1, 1))[0]);

        try {
            $this->parser()->parse($xml, 'test.xml');
            self::fail('The file should have been rejected.');
        } catch (StatementRejected $e) {
            self::assertStringContainsString('Line 2', $e->getMessage());
        }
    }

    public function testADocumentTypeDeclarationIsRefusedBeforeAnythingIsRead(): void
    {
        $evil = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><Document>&x;</Document>';

        try {
            $this->parser()->parse($evil, 'evil.xml');
            self::fail('The file should have been refused.');
        } catch (StatementRejected $e) {
            self::assertSame(StatementRejected::UNSAFE_DOCUMENT, $e->reason);
        }
    }

    public function testAHugeFileIsRefusedUnread(): void
    {
        try {
            $this->parser()->parse(str_repeat(' ', 300_000), 'big.xml');
            self::fail('The file should have been refused.');
        } catch (StatementRejected $e) {
            self::assertSame(StatementRejected::TOO_LARGE, $e->reason);
        }
    }
}
