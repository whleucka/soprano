<?php

namespace App\Console\Commands;

use App\Services\Soprano\SyncMetaService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'soprano:sync-meta', description: 'Update track meta with ID3 tag info')]
class SyncMetaCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'refresh',
            null,
            InputOption::VALUE_NONE,
            'Re-parse and overwrite meta for every track (default: only tracks with no meta)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refresh = (bool) $input->getOption('refresh');

        $service = container()->get(SyncMetaService::class);
        $result = $service->sync($refresh);

        $stats = sprintf(
            "  scanned: %d, written: %d, failed: %d",
            $result->scanned,
            $result->written,
            $result->failed
        );

        if ($result->success) {
            $output->writeln("<info>Track meta successfully synchronized</info>" . ($refresh ? " (refresh)" : ""));
            $output->writeln($stats);
            return Command::SUCCESS;
        }

        $output->writeln("<error>Sync meta error</error> {$result->error}");
        $output->writeln($stats);
        return Command::FAILURE;
    }
}
