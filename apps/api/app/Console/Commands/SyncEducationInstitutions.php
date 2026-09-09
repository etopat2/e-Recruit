<?php

namespace App\Console\Commands;

use App\Services\OfficialEducationInstitutionDirectory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('erecruit:sync-education-institutions {--source=all : all, nche, or tvet}')]
#[Description('Synchronize current official NCHE and MoES TVET institution directories')]
class SyncEducationInstitutions extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OfficialEducationInstitutionDirectory $directory): int
    {
        $source = strtolower((string) $this->option('source'));
        if (! in_array($source, ['all', 'nche', 'tvet'], true)) {
            $this->error('The source must be all, nche, or tvet.');

            return self::FAILURE;
        }

        try {
            $counts = match ($source) {
                'nche' => ['nche' => $directory->syncHigherEducation()],
                'tvet' => ['tvet' => $directory->syncTvet()],
                default => $directory->syncCurrentDirectories(),
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
}
