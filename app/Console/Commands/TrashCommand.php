<?php

namespace App\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'soprano:trash', description: 'List or purge files trashed by soprano:duplicates')]
class TrashCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'empty',
            null,
            InputOption::VALUE_NONE,
            'Permanently delete trashed files. Without --force you are asked to confirm.',
        );
        $this->addOption(
            'older-than',
            null,
            InputOption::VALUE_REQUIRED,
            'With --empty, only delete files trashed more than N days ago.',
        );
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Skip the confirmation prompt when emptying.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $trashPath = rtrim((string) config('soprano.trash_path'), '/');

        if ($trashPath === '' || !is_dir($trashPath)) {
            $output->writeln("<info>Trash is empty.</info> (No trash folder yet.)");
            return Command::SUCCESS;
        }

        $files = array_values(array_filter(
            glob($trashPath . '/*') ?: [],
            'is_file',
        ));

        if (!$files) {
            $output->writeln("<info>Trash is empty.</info> {$trashPath}");
            return Command::SUCCESS;
        }

        $olderThan = $input->getOption('older-than');
        $cutoff    = null;
        if ($olderThan !== null) {
            if (!ctype_digit((string) $olderThan)) {
                $output->writeln("<error>--older-than must be a whole number of days.</error>");
                return Command::FAILURE;
            }
            $cutoff = time() - ((int) $olderThan * 86400);
        }

        if (!$input->getOption('empty')) {
            return $this->list($output, $trashPath, $files);
        }

        // Narrow to the files actually eligible for deletion.
        $targets = $cutoff === null
            ? $files
            : array_values(array_filter($files, static fn(string $f) => filemtime($f) < $cutoff));

        if (!$targets) {
            $output->writeln(sprintf(
                "<info>Nothing to delete.</info> No files older than %s day(s).",
                $olderThan,
            ));
            return Command::SUCCESS;
        }

        $bytes = array_sum(array_map('filesize', $targets));

        if (!$input->getOption('force')) {
            /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
            $helper   = $this->getHelper('question');
            $question = new ConfirmationQuestion(sprintf(
                "Permanently delete %d file(s) (%s) from %s? [y/N] ",
                count($targets),
                $this->humanSize($bytes),
                $trashPath,
            ), false);

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln("<comment>Aborted — nothing deleted.</comment>");
                return Command::SUCCESS;
            }
        }

        $deleted = 0;
        foreach ($targets as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }

        $output->writeln(sprintf(
            "<info>Done.</info> Deleted %d of %d file(s), freed %s.",
            $deleted,
            count($targets),
            $this->humanSize($bytes),
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<int,string> $files
     */
    private function list(OutputInterface $output, string $trashPath, array $files): int
    {
        $bytes = array_sum(array_map('filesize', $files));

        $output->writeln("");
        $output->writeln(sprintf(
            "<comment>Trash: %d file(s), %s</comment>  <fg=gray>%s</>",
            count($files),
            $this->humanSize($bytes),
            $trashPath,
        ));
        foreach ($files as $file) {
            $output->writeln(sprintf(
                "    %9s  %s",
                $this->humanSize(filesize($file)),
                basename($file),
            ));
        }
        $output->writeln("");
        $output->writeln("Pass <fg=yellow>--empty</> to delete (optionally <fg=yellow>--older-than=N</> days, <fg=yellow>--force</> to skip the prompt).");

        return Command::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        $size  = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $bytes : sprintf('%.1f', $size)) . ' ' . $units[$i];
    }
}
