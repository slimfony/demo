<?php

declare(strict_types=1);

namespace Demo\Camt;

/**
 * Reads one CAMT.053 file: bounded in size, no DOCTYPE (so no entities and nothing is fetched from anywhere),
 * checked against the XSD, and only then read. Whatever is wrong ends as a StatementRejected with the line of the error.
 */
final class CamtParser
{
    private const string NAMESPACE = 'urn:iso:std:iso:20022:tech:xsd:camt.053.001.02';
    private const int MAX_BYTES = 262_144;

    public function __construct(private readonly string $schemaPath) {}

    /**
     * @throws StatementRejected
     */
    public function parse(string $xml, string $file): Statement
    {
        if (\strlen($xml) > self::MAX_BYTES) {
            throw new StatementRejected(StatementRejected::TOO_LARGE, $file, \sprintf('The file is larger than %d bytes.', self::MAX_BYTES));
        }
        if (false !== stripos($xml, '<!DOCTYPE') || false !== stripos($xml, '<!ENTITY')) {
            throw new StatementRejected(StatementRejected::UNSAFE_DOCUMENT, $file, 'A document type declaration is not accepted.');
        }

        $dom = $this->load($xml, $file);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('c', self::NAMESPACE);

        $opening = null;
        $closing = null;
        foreach ($this->elements($xpath, '/c:Document/c:BkToCstmrStmt/c:Stmt/c:Bal') as $balance) {
            $cents = Money::cents($this->text($xpath, 'c:Amt', $balance)) * (Entry::DEBIT === $this->text($xpath, 'c:CdtDbtInd', $balance) ? -1 : 1);
            if ('OPBD' === $this->text($xpath, 'c:Tp/c:CdOrPrtry/c:Cd', $balance)) {
                $opening = $cents;
            } else {
                $closing = $cents;
            }
        }

        $entries = [];
        foreach ($this->elements($xpath, '/c:Document/c:BkToCstmrStmt/c:Stmt/c:Ntry') as $entry) {
            $direction = $this->text($xpath, 'c:CdtDbtInd', $entry);
            $party = Entry::DEBIT === $direction ? 'CdtrAcct' : 'DbtrAcct'; // the other side of the payment
            $entries[] = new Entry(
                $this->text($xpath, 'c:NtryRef', $entry),
                Money::cents($this->text($xpath, 'c:Amt', $entry)),
                $direction,
                $this->text($xpath, 'c:BookgDt/c:Dt', $entry),
                $this->optional($xpath, "c:NtryDtls/c:TxDtls/c:RltdPties/c:$party/c:Id/c:IBAN", $entry),
                $this->optional($xpath, 'c:NtryDtls/c:TxDtls/c:RmtInf/c:Ustrd', $entry),
            );
        }

        $statement = '/c:Document/c:BkToCstmrStmt/c:Stmt/';

        return new Statement(
            $file,
            $this->text($xpath, $statement . 'c:Id'),
            $this->text($xpath, $statement . 'c:Acct/c:Id/c:IBAN'),
            $this->text($xpath, $statement . 'c:Acct/c:Ccy'),
            $this->text($xpath, $statement . 'c:FrToDt/c:FrDtTm'),
            $this->text($xpath, $statement . 'c:FrToDt/c:ToDtTm'),
            $opening ?? 0, // the schema demands both balances
            $closing ?? 0,
            $entries,
        );
    }

    private function load(string $xml, string $file): \DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            // LIBXML_NONET: never touch the network. Entities are not substituted (no LIBXML_NOENT) and DOCTYPE was refused above.
            if (!$dom->loadXML($xml, \LIBXML_NONET) || !$dom->schemaValidate($this->schemaPath)) {
                $errors = libxml_get_errors();
                $first = $errors[0] ?? null;
                throw new StatementRejected(
                    StatementRejected::SCHEMA_INVALID,
                    $file,
                    null === $first ? 'The file does not follow the schema.' : \sprintf('Line %d: %s', $first->line, trim($first->message)),
                );
            }

            return $dom;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function text(\DOMXPath $xpath, string $expression, ?\DOMElement $context = null): string
    {
        return $this->optional($xpath, $expression, $context) ?? throw new \UnexpectedValueException('A required element is missing after schema validation: ' . $expression);
    }

    /**
     * @return list<\DOMElement>
     */
    private function elements(\DOMXPath $xpath, string $expression): array
    {
        $found = [];
        $nodes = $xpath->query($expression);
        foreach (false === $nodes ? [] : $nodes as $node) {
            if ($node instanceof \DOMElement) {
                $found[] = $node;
            }
        }

        return $found;
    }

    private function optional(\DOMXPath $xpath, string $expression, ?\DOMElement $context = null): ?string
    {
        $nodes = $xpath->query($expression, $context);
        $node = false === $nodes ? null : $nodes->item(0);

        return $node instanceof \DOMElement ? trim($node->textContent) : null;
    }
}
