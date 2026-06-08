<?php

namespace App\Console\Commands;

use App\Services\Soprano\MusicService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'soprano:duplicates', description: 'Report likely duplicate tracks, or interactively pick one to keep and remove the rest')]
class DuplicatesCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'cross',
            null,
            InputOption::VALUE_NONE,
            'Also include cross-album duplicates (same artist + title in different albums). Noisy — many are legit (greatest hits, live, singles).',
        );
        $this->addOption(
            'interactive',
            'i',
            InputOption::VALUE_NONE,
            'Step through each group, pick the copy to keep; the others are moved to the trash folder and removed from the database.',
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

        if ($input->getOption('interactive')) {
            return $this->runInteractive($input, $output, $music, $identical, $review, $showCross ? $cross : []);
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
        $output->writeln("Re-run with -i / --interactive to pick a copy to keep, or remove files by hand then re-run soprano:sync.");

        return Command::SUCCESS;
    }

    /**
     * Walk every group, ask which copy to keep, delete the rest.
     *
     * @param array<int,array> $identical
     * @param array<int,array> $review
     * @param array<int,array> $cross
     */
    private function runInteractive(
        InputInterface $input,
        OutputInterface $output,
        MusicService $music,
        array $identical,
        array $review,
        array $cross,
    ): int {
        $sections = [
            ['Likely identical files (same album + title + length)', $identical],
            ['Same album + title, differing length — review carefully', $review],
            ['Cross-album duplicates (same artist + title, different albums)', $cross],
        ];

        $groups = [];
        foreach ($sections as [$label, $list]) {
            foreach ($list as $group) {
                $groups[] = [$label, $group];
            }
        }

        if (!$groups) {
            $output->writeln("<info>Nothing to do.</info> (Cross-album groups need --cross.)");
            return Command::SUCCESS;
        }

        $output->writeln("");
        $output->writeln(sprintf("<comment>Interactive cleanup: %d group(s).</comment>", count($groups)));
        $output->writeln("For each group, enter the number of the copy to <fg=green>keep</> — the rest are moved to the trash folder and removed from the database.");
        $output->writeln("Other keys: <fg=yellow>s</> skip this group, <fg=yellow>q</> quit. Nothing is touched until you choose.");

        $helper        = $this->getHelper('question');
        $totalRemoved  = 0;
        $totalFiles    = 0;
        $skipped       = 0;
        $missing       = [];
        $trashPath     = '';

        $index = 0;
        foreach ($groups as [$label, $group]) {
            $index++;
            $first  = $group[0];
            $artist = (string) ($first['artist'] ?? '?');
            $title  = (string) ($first['title'] ?? '?');

            $output->writeln("");
            $output->writeln(sprintf(
                "<fg=cyan>[%d/%d] %s — %s</> (%d copies)  <fg=gray>%s</>",
                $index,
                count($groups),
                $artist,
                $title,
                count($group),
                $label,
            ));
            foreach ($group as $n => $row) {
                $output->writeln(sprintf(
                    "    <fg=green>%d)</> [%s] %-7s  %s",
                    $n + 1,
                    $row['album'] ?? '?',
                    $row['playtime_string'] ?? '?',
                    $row['pathname'] ?? '?',
                ));
            }

            $choice = $this->askChoice($helper, $input, $output, count($group));

            if ($choice === 'q') {
                $output->writeln("<comment>Quitting — no further changes.</comment>");
                break;
            }
            if ($choice === 's') {
                $skipped++;
                $output->writeln("<fg=gray>Skipped.</>");
                continue;
            }

            // $choice is the 1-based index to keep.
            $keepIdx  = $choice - 1;
            $toRemove = [];
            foreach ($group as $n => $row) {
                if ($n !== $keepIdx) {
                    $toRemove[] = (int) $row['track_id'];
                }
            }

            $summary       = $music->removeTracks($toRemove);
            $totalRemoved += $summary->deleted_rows;
            $totalFiles   += $summary->trashed_files;
            $missing       = array_merge($missing, $summary->missing_files);
            $trashPath     = $summary->trash_path;

            $output->writeln(sprintf(
                "    <info>Kept</> copy %d; trashed %d file(s), removed %d row(s).",
                $choice,
                $summary->trashed_files,
                $summary->deleted_rows,
            ));
        }

        $output->writeln("");
        $output->writeln(sprintf(
            "<info>Done.</info> Trashed %d file(s) and removed %d track row(s); %d group(s) skipped.",
            $totalFiles,
            $totalRemoved,
            $skipped,
        ));
        if ($missing) {
            $output->writeln(sprintf(
                "<comment>%d row(s) had no file on disk (removed from DB only).</comment>",
                count($missing),
            ));
        }
        if ($totalRemoved > 0) {
            if ($trashPath !== '') {
                $output->writeln(sprintf("Trashed files are recoverable at <fg=cyan>%s</> until you clear it.", $trashPath));
            }
            $output->writeln("Empty albums/artists were pruned. Re-run soprano:sync if anything looks off.");
        }

        return Command::SUCCESS;
    }

    /**
     * Prompt until the answer is a valid copy number, 's', or 'q'.
     *
     * @return int|string the 1-based copy to keep, or 's'/'q'
     */
    private function askChoice(QuestionHelper $helper, InputInterface $input, OutputInterface $output, int $count)
    {
        $question = new Question(sprintf("  Keep which copy? [1-%d / s / q]: ", $count));
        $question->setValidator(function ($answer) use ($count) {
            $answer = strtolower(trim((string) $answer));
            if ($answer === 's' || $answer === 'q') {
                return $answer;
            }
            if (ctype_digit($answer)) {
                $n = (int) $answer;
                if ($n >= 1 && $n <= $count) {
                    return $n;
                }
            }
            throw new \RuntimeException(sprintf('Enter a number 1-%d, or s / q.', $count));
        });
        $question->setMaxAttempts(null);

        return $helper->ask($input, $output, $question);
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
