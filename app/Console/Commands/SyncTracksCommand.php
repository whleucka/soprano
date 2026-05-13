<?php

namespace App\Console\Commands;

use App\Services\Soprano\SyncTracksService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'soprano:sync-tracks', description: 'Synchronize music tracks library')]
class SyncTracksCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = config("soprano.music_path");

        if (!file_exists($path)) {
            $output->writeln("<error>The path: {$path} does not exist</error>");
            return Command::FAILURE;
        }

        $service = container()->get(SyncTracksService::class);
        $result = $service->sync($path);

        $stats = sprintf(
            "  scanned: %d, inserted: %d, skipped: %d",
            $result->scanned,
            $result->inserted,
            $result->skipped
        );

        if ($result->success) {
            $output->writeln("<info>Path successfully synchronized</info> {$path}");
            $output->writeln($stats);
            return Command::SUCCESS;
        }

        $output->writeln("<error>Sync tracks error</error> {$result->error} {$path}");
        $output->writeln($stats);
        return Command::FAILURE;
    }
}
