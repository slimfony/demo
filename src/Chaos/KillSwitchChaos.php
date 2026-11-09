<?php

declare(strict_types=1);

namespace Demo\Chaos;

use Psr\Log\LoggerInterface;

/** Dies the way a crashed process dies, in the middle of a batch: no clean-up, no acknowledgement. The container restarts it. */
final class KillSwitchChaos implements Chaos
{
    /** @var \Closure(): never */
    private readonly \Closure $die;

    /**
     * @param (\Closure(): never)|null $die defaults to ending the process at once (exit code 137, what a SIGKILL looks like)
     */
    public function __construct(
        private readonly KillTokens $tokens,
        private readonly LoggerInterface $logger,
        ?\Closure $die = null,
    ) {
        $this->die = $die ?? static function (): never {
            exit(137);
        };
    }

    #[\Override]
    public function strike(): void
    {
        if ($this->tokens->take()) {
            $this->logger->critical('Chaos: this importer is being killed in the middle of a batch', ['worker' => (string) gethostname()]);
            ($this->die)();
        }
    }
}
