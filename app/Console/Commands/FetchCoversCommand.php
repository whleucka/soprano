<?php

namespace App\Console\Commands;

use App\Services\Soprano\CoverArtService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'soprano:fetch-covers', description: 'Fetch album art from ID3 tag')]
class FetchCoversCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $service = container()->get(CoverArtService::class);
        $result = $service->fetchCovers();

        $stats = sprintf(
            "  scanned: %d, updated: %d, skipped: %d",
            $result->scanned,
            $result->updated,
            $result->skipped
        );

        if ($result->success) {
            $output->writeln("<info>Cover art successfully fetched</info>");
            $output->writeln($stats);
            return Command::SUCCESS;
        }

        $output->writeln("<error>Fetch covers error</error> {$result->error}");
        $output->writeln($stats);
        return Command::FAILURE;
    }
}
