<?php

namespace App\Console\Commands;

use App\Services\Soprano\SyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'soprano:sync', description: 'Synchronize music library (tracks, artists, albums, meta, covers)')]
class SyncCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'covers',
            null,
            InputOption::VALUE_NONE,
            'Only refresh album cover art (fills missing covers; no track scan)',
        );
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'With --covers, re-extract every album cover, not just missing ones',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('covers')) {
            return $this->syncCovers($input, $output);
        }

        $path = config("soprano.music_path");

        if (!file_exists($path)) {
            $output->writeln("<error>The path: {$path} does not exist</error>");
            return Command::FAILURE;
        }

        $service = container()->get(SyncService::class);
        $result = $service->sync($path);

        $stats = sprintf(
            "  scanned: %d, inserted: %d, removed: %d, skipped: %d, failed: %d",
            $result->scanned,
            $result->inserted,
            $result->removed,
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

    private function syncCovers(InputInterface $input, OutputInterface $output): int
    {
        $force   = (bool) $input->getOption('force');
        $service = container()->get(SyncService::class);
        $result  = $service->syncCovers($force);

        $stats = sprintf(
            "  scanned: %d, updated: %d, skipped: %d, failed: %d",
            $result->scanned,
            $result->updated,
            $result->skipped,
            $result->failed
        );

        $scope = $force ? 'all albums' : 'albums missing covers';

        if ($result->success) {
            $output->writeln("<info>Cover art refreshed</info> ({$scope})");
            $output->writeln($stats);
            return Command::SUCCESS;
        }

        $output->writeln("<error>Cover sync error</error> {$result->error}");
        $output->writeln($stats);
        return Command::FAILURE;
    }
}
