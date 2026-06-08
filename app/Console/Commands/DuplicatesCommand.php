<?php

namespace App\Console\Commands;

use App\Services\Soprano\MusicService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'soprano:duplicates', description: 'Report likely duplicate tracks (report only, nothing is deleted)')]
class DuplicatesCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'cross',
            null,
            InputOption::VALUE_NONE,
            'Also list cross-album duplicates (same artist + title in different albums). Noisy — many are legit (greatest hits, live, singles).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $music  = container()->get(MusicService::class);
        $result = $music->findDuplicateTracks();

        $within     = $result->within_album;
        $cross      = $result->cross_album;
        $showCross  = (bool) $input->getOption('cross');

        if (!$within && !$cross) {
            $output->writeln("<info>No duplicate tracks found.</info>");
            return Command::SUCCESS;
        }

        // Within-album: split by confidence. Identical playtime across every copy
        // means the same audio twice (a "(1)" / "_<ticks>" copy) — high confidence.
        // Differing lengths are usually legit (DJ mixes, multiple performances).
        $identical = [];
        $review    = [];
        foreach ($within as $group) {
            if ($this->sameLength($group)) {
                $identical[] = $group;
            } else {
                $review[] = $group;
            }
        }

        if ($identical) {
            $output->writeln("");
            $output->writeln(sprintf(
                "<comment>Likely identical files (same album + title + length): %d group(s)</comment>",
                count($identical),
            ));
            foreach ($identical as $group) {
                $this->printGroup($output, $group);
            }
        }

        if ($review) {
            $output->writeln("");
            $output->writeln(sprintf(
                "<comment>Same album + title, differing length — review: %d group(s)</comment>",
                count($review),
            ));
            foreach ($review as $group) {
                $this->printGroup($output, $group);
            }
        }

        if ($showCross && $cross) {
            $output->writeln("");
            $output->writeln(sprintf(
                "<comment>Cross-album duplicates (same artist + title, different albums): %d group(s)</comment>",
                count($cross),
            ));
            foreach ($cross as $group) {
                $this->printGroup($output, $group);
            }
        }

        $output->writeln("");
        $output->writeln(sprintf(
            "<info>Done.</info> %d likely-identical, %d to-review (within album); %d cross-album group(s)%s.",
            count($identical),
            count($review),
            count($cross),
            $showCross ? '' : ' (hidden — pass --cross to list)',
        ));
        $output->writeln("Review and remove files by hand, then re-run soprano:sync.");

        return Command::SUCCESS;
    }

    /**
     * True when every copy in the group has the same, known playtime.
     *
     * @param array<int,array> $group
     */
    private function sameLength(array $group): bool
    {
        $lengths = array_map(static fn(array $r) => $r['length_ms'] ?? null, $group);
        if (in_array(null, $lengths, true)) {
            return false;
        }
        return count(array_unique($lengths)) === 1;
    }

    /**
     * @param array<int,array> $group
     */
    private function printGroup(OutputInterface $output, array $group): void
    {
        $first  = $group[0];
        $artist = (string) ($first['artist'] ?? '?');
        $title  = (string) ($first['title'] ?? '?');

        $output->writeln(sprintf("  <fg=cyan>%s — %s</> (%d copies)", $artist, $title, count($group)));
        foreach ($group as $row) {
            $output->writeln(sprintf(
                "    [%s] %-7s  %s",
                $row['album'] ?? '?',
                $row['playtime_string'] ?? '?',
                $row['pathname'] ?? '?',
            ));
        }
    }
}
