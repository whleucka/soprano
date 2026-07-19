<?php

namespace App\Console\Commands;

use App\Services\Soprano\StationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'soprano:stations', description: 'Report station pool sizes and percentile thresholds against the analyzed library')]
class StationsCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'sample',
            's',
            InputOption::VALUE_REQUIRED,
            'Also print N random tracks per station (artist/title + the features being filtered) to eyeball threshold fit.',
            '0',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stations = container()->get(StationService::class);
        $report   = $stations->report();

        if ($report->analyzed === 0) {
            $output->writeln('<comment>No analyzed tracks yet — run the features backfill first (jobs/soprano_features.php).</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            'Analyzed <info>%d</info> of %d tracks (%d extractor errors)',
            $report->analyzed,
            $report->total,
            $report->errors,
        ));

        $output->writeln('');
        $output->writeln('<info>Library percentile thresholds</info>');
        $thresholds = new Table($output);
        $thresholds->setHeaders(['token', 'value']);
        foreach ($report->thresholds ?? [] as $token => $value) {
            $thresholds->addRow([$token, $value]);
        }
        $thresholds->render();

        $output->writeln('');
        $output->writeln('<info>Station pools</info>');
        $pools = new Table($output);
        $pools->setHeaders(['station', 'pool', '% of analyzed', 'hours']);
        foreach ($report->stations as $s) {
            $pools->addRow([
                $s['name'],
                $s['pool'],
                sprintf('%.1f%%', $s['pool'] / $report->analyzed * 100),
                $s['hours'] ? sprintf('%02d:00–%02d:00', $s['hours'][0], $s['hours'][1]) : '—',
            ]);
        }
        $pools->render();

        $sample = max(0, (int) $input->getOption('sample'));
        if ($sample > 0) {
            foreach ($report->stations as $slug => $s) {
                $output->writeln('');
                $output->writeln(sprintf('<info>%s</info> — %s', $s['name'], $s['where']));
                $tracks = $stations->sample($slug, $sample);
                if (!$tracks) {
                    $output->writeln('<comment>  (empty pool)</comment>');
                    continue;
                }
                $rows = new Table($output);
                $rows->setHeaders(['artist', 'title', 'bpm', 'dance', 'energy', 'dB', 'key']);
                foreach ($tracks as $t) {
                    $rows->addRow([
                        $t['artist'],
                        $t['title'],
                        sprintf('%.0f', $t['bpm']),
                        sprintf('%.2f', $t['danceability']),
                        sprintf('%.3f', $t['energy']),
                        sprintf('%.1f', $t['avg_loudness_db']),
                        trim(($t['key_root'] ?? '') . ' ' . ($t['key_scale'] ?? '')),
                    ]);
                }
                $rows->render();
            }
        }

        return Command::SUCCESS;
    }
}
