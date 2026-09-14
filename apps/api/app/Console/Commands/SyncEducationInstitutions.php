<?php

namespace App\Console\Commands;

use App\Services\OfficialEducationInstitutionDirectory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('erecruit:sync-education-institutions {--source=all : all, nche, tvet, or emis}')]
#[Description('Synchronize current official NCHE, MoES TVET, and MoES EMIS institution directories')]
class SyncEducationInstitutions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OfficialEducationInstitutionDirectory $directory): int
    {
        $source = strtolower((string) $this->option('source'));
        if (! in_array($source, ['all', 'nche', 'tvet', 'emis'], true)) {
            $this->error('The source must be all, nche, tvet, or emis.');

            return self::FAILURE;
        }

        try {
            $counts = match ($source) {
                'nche' => ['nche' => $directory->syncHigherEducation()],
                'tvet' => ['tvet' => $directory->syncTvet()],
                'emis' => ['emis' => $directory->syncSchools($this->schoolProgress())],
                default => [
                    ...$directory->syncCurrentDirectories(),
                    'emis' => $directory->syncSchools($this->schoolProgress()),
                ],
            };
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($counts as $name => $count) {
            $this->info(strtoupper($name).": {$count} official institutions synchronized.");
        }

        return self::SUCCESS;
    }

    /** @return callable(string, int, int, int, int): void */
    private function schoolProgress(): callable
    {
        return function (string $partition, int $page, int $pages, int $imported, int $total): void {
            $this->line("EMIS {$partition}: page {$page}/{$pages}; {$imported}/{$total} records synchronized.");
        };
    }
}
