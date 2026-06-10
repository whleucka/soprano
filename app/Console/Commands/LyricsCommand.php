<?php

namespace App\Console\Commands;

use App\Services\Soprano\LyricsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'soprano:lyrics',
    description: 'Backfill track lyrics from LRCLIB exact match (keyless)',
)]
class LyricsCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max tracks to process (0 = all)', '0')
             ->addOption('recheck', null, InputOption::VALUE_NONE, 'Retry tracks previously checked but still without lyrics');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit   = (int) $input->getOption('limit');
        $recheck = (bool) $input->getOption('recheck');

        $service = container()->get(LyricsService::class);
        $result  = $service->backfill($limit, $recheck);

        $stats = sprintf(
            "  checked: %d, found: %d, missed: %d, failed: %d",
            $result->checked,
            $result->found,
            $result->missed,
            $result->failed,
        );

        if ($result->success) {
            $output->writeln("<info>Lyrics backfilled</info>");
            $output->writeln($stats);
            return Command::SUCCESS;
        }

        $output->writeln("<error>Lyrics backfill error</error> {$result->error}");
        $output->writeln($stats);
        return Command::FAILURE;
    }
}
