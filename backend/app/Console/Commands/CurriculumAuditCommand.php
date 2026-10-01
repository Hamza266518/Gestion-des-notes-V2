<?php

namespace App\Console\Commands;

use App\Services\CurriculumAuditService;
use App\Support\Curriculum\OfficialCurriculum;
use Illuminate\Console\Command;

class CurriculumAuditCommand extends Command
{
    protected $signature = 'curriculum:audit
        {--filiere= : Limit to a filiere code (AS, IA, IP)}
        {--csv= : Write the unit diff to this CSV path}';

    protected $description = 'Compare the database against the official curriculum documents (read-only)';

    public function handle(CurriculumAuditService $service): int
    {
        $filiere = $this->option('filiere');
        $report = $service->audit($filiere);

        $this->newLine();
        $this->line('=== OFFICIAL CURRICULUM AUDIT (read-only) ===');
        $this->line('Source: word/Tableau des Coefficient des unités.docx');
        $this->newLine();

        foreach ($report['summary'] as $status => $count) {
            $label = match ($status) {
                'ok' => 'already correct',
                'change' => 'coefficient differs from official',
                'unresolved' => 'no authoritative source (left untouched)',
                'no_source' => 'unit not found in official table (left untouched)',
                default => $status,
            };
            $this->line(sprintf('  %-12s %3d   %s', $status, $count, $label));
        }

        foreach ($report['unresolved'] as $key => $why) {
            $this->newLine();
            $this->warn("UNRESOLVED [$key]: $why");
        }

        foreach ($report['conflicts'] as $key => $why) {
            $this->newLine();
            $this->warn("CONFLICT [$key]: $why");
        }

        if ($report['unmatched_official'] !== []) {
            $this->newLine();
            $this->line('Official rows with no matching unit in the database:');
            foreach ($report['unmatched_official'] as $m) {
                $this->line(sprintf('  %s an%d  %s  (coef %d)', $m['filiere'], $m['annee'], $m['nom'], $m['coefficient']));
            }
        }

        $changes = array_values(array_filter($report['unites'], fn($r) => $r['status'] === 'change'));
        if ($changes !== []) {
            $this->newLine();
            $this->line('Units whose coefficient would change:');
            $this->table(
                ['id', 'prog', 'an', 'S', 'current', 'official', 'notes', 'name'],
                array_map(fn($r) => [
                    $r['id'],
                    $r['filiere'],
                    $r['annee'],
                    $r['semestre'],
                    $r['current'],
                    $r['official'],
                    $r['notes'],
                    $r['nom'],
                ], $changes)
            );
        }

        $seq = $service->sequenceAudit();
        $this->newLine();
        $this->line('=== SEQUENCE INTEGRITY ===');
        foreach ($seq['summary'] as $k => $v) {
            $this->line(sprintf('  %-16s %3d', $k, $v));
        }
        $this->comment('  coef_equals_nbc > 0 confirms sequences.coefficient only mirrors nombre_controles and must not be used as a weight.');

        $ties = array_values(array_filter($seq['sequences'], fn($r) => $r['ordre_tie']));
        if ($ties !== []) {
            $this->newLine();
            $this->line('Duplicate ordre inside the same unit:');
            $this->table(
                ['sequence', 'unite', 'ordre', 'name'],
                array_map(fn($r) => [$r['id'], $r['unite_id'], $r['ordre'], $r['nom']], $ties)
            );
        }

        $csv = $this->option('csv');
        if ($csv) {
            $handle = fopen($csv, 'w');
            fputcsv($handle, ['id', 'filiere', 'annee', 'semestre', 'ordre', 'nom', 'current', 'official', 'status', 'notes', 'real_controles']);
            foreach ($report['unites'] as $r) {
                fputcsv($handle, [
                    $r['id'], $r['filiere'], $r['annee'], $r['semestre'], $r['ordre'],
                    $r['nom'], $r['current'], $r['official'], $r['status'], $r['notes'], $r['real_controles'],
                ]);
            }
            fclose($handle);
            $this->newLine();
            $this->info("CSV written to {$csv}");
        }

        $this->newLine();
        $this->line('Stage coefficient required by every official table: ' . OfficialCurriculum::STAGE_COEFFICIENT);
        $this->line('Final mark weights: ' . json_encode(OfficialCurriculum::FINAL_WEIGHTS));

        return self::SUCCESS;
    }
}
