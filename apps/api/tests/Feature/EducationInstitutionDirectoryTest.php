<?php

namespace Tests\Feature;

use App\Models\EducationInstitution;
use App\Models\User;
use App\Services\OfficialEducationInstitutionDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EducationInstitutionDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_search_filters_by_level_and_never_returns_more_than_seven_matches(): void
    {
        Sanctum::actingAs(User::factory()->create());
        foreach (range(1, 9) as $index) {
            $name = "Searchable University {$index}";
            EducationInstitution::factory()->create([
                'name' => $name,
                'normalized_name' => Str::upper(Str::ascii($name)),
                'qualification_levels' => ["Bachelor's Degree"],
            ]);
        }
        EducationInstitution::factory()->create([
            'name' => 'Searchable Primary School',
            'normalized_name' => 'SEARCHABLE PRIMARY SCHOOL',
            'qualification_levels' => ['PLE'],
        ]);

        $this->getJson('/api/v1/education-institutions?level='.urlencode("Bachelor's Degree").'&search=Searchable&limit=7')
            ->assertOk()
            ->assertJsonCount(7, 'data')
            ->assertJsonMissing(['name' => 'Searchable Primary School'])
            ->assertJsonStructure(['data' => [[
                'id', 'name', 'institution_type', 'district', 'registration_status',
                'operational_status', 'source', 'source_url', 'last_verified_at',
            ]]]);
    }

    public function test_search_requires_authentication_two_characters_and_a_limit_of_seven(): void
    {
        $this->getJson('/api/v1/education-institutions?level=UCE&search=Ma')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/education-institutions?level=UCE&search=M&limit=8')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search', 'limit']);
    }

    public function test_current_nche_and_tvet_directories_are_synchronized_with_provenance(): void
    {
        Http::fake([
            'https://unche.or.ug/institutions/' => Http::response(<<<'HTML'
                <table id="unche-table"><tbody>
                    <tr><td>Example University</td><td>University</td><td>Chartered University</td><td>Kampala</td><td>12</td></tr>
                    <tr><td>Example Institute</td><td>OTI</td><td>OTI – Registered</td><td>Gulu</td><td>4</td></tr>
                </tbody></table>
                HTML),
            'https://tvet.go.ug/institutions' => Http::response('<html>TVET directory</html>'),
            'https://tvet.go.ug/institution*' => Http::response([
                'draw' => 1,
                'recordsTotal' => 2,
                'recordsFiltered' => 2,
                'data' => [
                    [
                        'id' => 51,
                        'name' => '<a href="//example.test">Example Technical College</a>',
                        'institute_code' => 'ETC',
                        'registration_no' => 'TVET/001',
                        'institute_category' => 'Technical College',
                        'institute_status' => 'Registered',
                        'status' => '<span>Approved</span>',
                        'district' => 'Lira',
                        'is_enabled' => 'Active',
                        'is_accreditated' => 'Yes',
                        'license_no' => 'LIC-1',
                        'expiry_date' => '2027-01-01',
                    ],
                    [
                        'id' => 52,
                        'name' => 'Applicant Skills Centre',
                        'institute_category' => 'Skills Development Centre',
                        'institute_status' => 'Applied',
                        'status' => '<span>Approved</span>',
                        'district' => 'Wakiso',
                        'is_enabled' => 'Active',
                    ],
                ],
            ]),
        ]);

        $counts = app(OfficialEducationInstitutionDirectory::class)->syncCurrentDirectories();

        $this->assertSame(['nche' => 2, 'tvet' => 2], $counts);
        $this->assertDatabaseHas('education_institutions', [
            'source' => 'nche',
            'name' => 'Example University',
            'registration_status' => 'Chartered University',
            'active' => true,
        ]);
        $this->assertDatabaseHas('education_institutions', [
            'source' => 'moes_tvet',
            'source_id' => '51',
            'name' => 'Example Technical College',
            'registration_number' => 'TVET/001',
            'active' => true,
        ]);
        $this->assertDatabaseHas('education_institutions', [
            'source' => 'moes_tvet',
            'source_id' => '52',
            'active' => false,
        ]);
    }

    public function test_emis_school_search_is_cached_and_imports_only_the_seven_official_matches(): void
    {
        Cache::flush();
        $snapshot = htmlspecialchars(json_encode(['data' => []], JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_HTML5);
        $schoolCards = collect(range(1, 8))->map(fn (int $index): string => <<<HTML
            <div class="card school-card" wire:click="showSchoolDetails({$index})">
                <span class="title text-dark">Official School {$index}</span>
                <div class="detail-item"><span class="text-soft">KAMPALA</span></div>
                <div class="detail-item"><span class="text-soft">SECONDARY SCHOOL</span></div>
                <span class="badge">ACTIVE</span>
            </div>
            HTML)->take(7)->implode('');

        Http::fake(function (Request $request) use ($snapshot, $schoolCards) {
            if ($request->method() === 'GET') {
                return Http::response("<div wire:snapshot=\"{$snapshot}\" data-csrf=\"csrf-token\"></div>");
            }

            return Http::response(['components' => [['effects' => ['html' => $schoolCards]]]]);
        });

        $directory = app(OfficialEducationInstitutionDirectory::class);
        $this->assertSame(7, $directory->refreshSchoolMatches('UCE', 'Official'));
        $this->assertSame(0, $directory->refreshSchoolMatches('UCE', 'Official'));

        $this->assertDatabaseCount('education_institutions', 7);
        $institution = EducationInstitution::query()->firstOrFail();
        $this->assertSame(['UCE', 'UACE'], $institution->qualification_levels);
        $this->assertSame('moes_emis', $institution->source);
        Http::assertSentCount(2);
    }
}
