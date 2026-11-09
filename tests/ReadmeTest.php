<?php

declare(strict_types=1);

namespace Demo\Tests;

use PHPUnit\Framework\TestCase;

final class ReadmeTest extends TestCase
{
    public function testTheContentsListPointsAtRealHeadingsAndMissesNone(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        preg_match('/^\*\*Contents\*\*\n\n((?:- \[.*\n)+)/m', $readme, $list);
        self::assertNotEmpty($list, 'the README has no contents list');
        preg_match_all('/^- \[(.+?)\]\(#(.+?)\)$/m', $list[1], $items, \PREG_SET_ORDER);

        preg_match_all('/^## (.+)$/m', $readme, $headings);
        $slug = static fn(string $heading): string => str_replace(' ', '-', (string) preg_replace('/[^a-z0-9 -]/', '', strtolower($heading)));

        self::assertSame(array_map($slug, $headings[1]), array_column($items, 2), 'the contents list must name every section, in order, with working anchors');
    }
}
