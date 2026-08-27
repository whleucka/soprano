<?php

namespace App\Console\Commands;

use App\Services\Soprano\TranscodeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soprano:transcode',
    description: 'Warm the Opus transcode cache for lossless tracks (FLAC, WAV, …)',
)]
class TranscodeCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max tracks to process (0 = all)', '0')
             ->addOption('force', null, InputOption::VALUE_NONE, 'Re-encode even when a fresh cache file exists')
             ->addOption('regain', null, InputOption::VALUE_NONE, 'Re-encode only cache files whose baked-in ReplayGain is stale')
             ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Wall-clock budget for --regain (0 = no budget)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $force = (bool) $input->getOption('force');

        $service = container()->get(TranscodeService::class);

        if ((bool) $input->getOption('regain')) {
            return $this->regain($service, $output, $limit, (int) $input->getOption('seconds'));
        }

        $result = $service->backfill($limit, $force);

        $stats = sprintf(
            "  checked: %d, encoded: %d, skipped: %d, failed: %d, pruned: %d",
            $result->checked,
            $result->encoded,
            $result->skipped,
            $result->failed,
            $result->pruned,
        );

        if ($result->success) {
            $output->writeln("<info>Transcode cache warmed</info>");
            $output->writeln($stats);
            return Command::SUCCESS;
        }

        $output->writeln("<error>Transcode error</error> {$result->error}");
        $output->writeln($stats);
        return Command::FAILURE;
    }

    /**
     * Manual counterpart to jobs/soprano_regain.php — same repair, on demand.
     * Uncapped by default, which is a couple of hours for a full backlog.
     */
    private function regain(TranscodeService $service, OutputInterface $output, int $limit, int $seconds): int
    {
        $result = $service->regain($limit, $seconds);

        $stats = sprintf(
            "  stale: %d, encoded: %d, skipped: %d, failed: %d, remaining: %d",
            $result->stale,
            $result->encoded,
            $result->skipped,
            $result->failed,
            $result->remaining,
        );

        if ($result->success) {
            $output->writeln("<info>Stale ReplayGain re-encoded</info>");
            $output->writeln($stats);
            return Command::SUCCESS;
        }

        $output->writeln("<error>Regain error</error> {$result->error}");
        $output->writeln($stats);
        return Command::FAILURE;
    }
}
