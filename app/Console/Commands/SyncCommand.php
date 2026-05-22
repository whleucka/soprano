<?php

namespace App\Console\Commands;

use App\Services\Soprano\SyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'soprano:sync', description: 'Synchronize music library (tracks, artists, albums, meta, covers)')]
class SyncCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = config("soprano.music_path");

        if (!file_exists($path)) {
            $output->writeln("<error>The path: {$path} does not exist</error>");
            return Command::FAILURE;
        }

        $service = container()->get(SyncService::class);
        $result = $service->sync($path);

        $stats = sprintf(
            "  scanned: %d, inserted: %d, skipped: %d, failed: %d",
            $result->scanned,
            $result->inserted,
            $result->skipped,
            $result->failed
        );

        if ($result->success) {
            $output->writeln("<info>Library successfully synchronized</info> {$path}");
            $output->writeln($stats);
            return Command::SUCCESS;
        }

        $output->writeln("<error>Sync error</error> {$result->error} {$path}");
        $output->writeln($stats);
        return Command::FAILURE;
    }
}
