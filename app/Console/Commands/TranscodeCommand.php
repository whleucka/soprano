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
             ->addOption('force', null, InputOption::VALUE_NONE, 'Re-encode even when a fresh cache file exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $force = (bool) $input->getOption('force');

        $service = container()->get(TranscodeService::class);
        $result  = $service->backfill($limit, $force);

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
}
