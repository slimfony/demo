<?php

declare(strict_types=1);

namespace Demo\Tests\Chaos;

use Demo\Chaos\KillSwitchChaos;
use Demo\Chaos\KillTokens;
use Demo\Tests\Support\PostgresTestCase;
use Psr\Log\NullLogger;

final class KillSwitchTest extends PostgresTestCase
{
    public function testOneTokenKillsExactlyOneImporterAndOnlyWhenThereIsOne(): void
    {
        $tokens = new KillTokens($this->connection);
        $deaths = new class {
            public int $count = 0;
        };
        $chaos = new KillSwitchChaos($tokens, new NullLogger(), static function () use ($deaths): never {
            ++$deaths->count;

            throw new \RuntimeException('the process would end here');
        });

        $chaos->strike();
        self::assertSame(0, $deaths->count, 'nobody asked: nobody dies');

        $tokens->add();
        try {
            $chaos->strike();
            self::fail('The importer should have been killed.');
        } catch (\RuntimeException) {
        }
        self::assertSame(1, $deaths->count);

        $chaos->strike();
        self::assertSame(1, $deaths->count, 'the token is used up');
        self::assertSame(0, $this->rows('chaos'));
    }

    public function testTwoImportersLookingAtTheSameTimeDoNotBothTakeTheToken(): void
    {
        $tokens = new KillTokens($this->connection);
        $tokens->add();

        self::assertTrue($tokens->take());
        self::assertFalse(new KillTokens($this->newConnection())->take());
    }
}
