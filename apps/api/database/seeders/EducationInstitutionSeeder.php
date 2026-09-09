<?php

namespace Database\Seeders;

use App\Services\OfficialEducationInstitutionDirectory;
use Illuminate\Database\Seeder;

class EducationInstitutionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(OfficialEducationInstitutionDirectory::class)->syncCurrentDirectories();
    }
}
