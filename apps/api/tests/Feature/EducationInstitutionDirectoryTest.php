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

    public function test_api_catalogue_is_the_authority_for_all_levels_and_directory_capabilities(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/education-qualification-levels')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.label', 'School education');

        $levels = collect($response->json('data'))->flatMap(fn (array $group): array => $group['options']);
        $this->assertCount(32, $levels);
        $this->assertCount(25, $levels->where('directory_searchable', true));
        $this->assertTrue($levels->firstWhere('value', 'UCE')['directory_searchable']);
        $this->assertFalse($levels->firstWhere('value', 'Advanced Craft Certificate (legacy TVET)')['directory_searchable']);
        $this->assertArrayNotHasKey('directory_source', $levels->first());
    }

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
        $this->getJson('/api/v1/education-institutions?level='.urlencode('Advanced Craft Certificate (legacy TVET)').'&search=College')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('level');
    }

    public function test_school_search_never_waits_for_the_remote_emis_service(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake();

        $this->getJson('/api/v1/education-institutions?level=UCE&search=Uncached')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        Http::assertNothingSent();
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

            return Http::response(['components' => [[
                'snapshot' => json_encode(['data' => ['schoolTotalResults' => 7]], JSON_THROW_ON_ERROR),
                'effects' => ['html' => $schoolCards],
            ]]]);
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

    public function test_complete_emis_school_directory_is_synchronized_in_bounded_pages(): void
    {
        config(['erecruit.institution_directory.emis_sync_page_size' => 24]);
        $initialSnapshot = htmlspecialchars(json_encode(['data' => []], JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_HTML5);
        $cards = fn (int $start, int $end, string $type): string => collect(range($start, $end))->map(fn (int $index): string => <<<HTML
            <div class="card school-card" wire:click="showSchoolDetails({$index})">
                <span class="title text-dark">Official {$type} School {$index}</span>
                <div class="detail-item"><span class="text-soft">KAMPALA</span></div>
                <div class="detail-item"><span class="text-soft">{$type} SCHOOL</span></div>
                <span class="badge">ACTIVE</span>
            </div>
            HTML)->implode('');

        Http::fakeSequence()
            ->push("<div wire:snapshot=\"{$initialSnapshot}\" data-csrf=\"csrf-token\"></div>")
            ->push(['components' => [[
                'snapshot' => json_encode(['data' => ['schoolTotalResults' => 25, 'schoolCurrentPage' => 1]], JSON_THROW_ON_ERROR),
                'effects' => ['html' => $cards(1, 24, 'PRIMARY')],
            ]]])
            ->push(['components' => [[
                'snapshot' => json_encode(['data' => ['schoolTotalResults' => 25, 'schoolCurrentPage' => 2]], JSON_THROW_ON_ERROR),
                'effects' => ['html' => $cards(25, 25, 'PRIMARY')],
            ]]])
            ->push("<div wire:snapshot=\"{$initialSnapshot}\" data-csrf=\"csrf-token\"></div>")
            ->push(['components' => [[
                'snapshot' => json_encode(['data' => ['schoolTotalResults' => 1, 'schoolCurrentPage' => 1]], JSON_THROW_ON_ERROR),
                'effects' => ['html' => $cards(26, 26, 'SECONDARY')],
            ]]]);

        $progressUpdates = [];
        $count = app(OfficialEducationInstitutionDirectory::class)->syncSchools(
            function (...$arguments) use (&$progressUpdates): void {
                $progressUpdates[] = $arguments;
            },
        );

        $this->assertSame(26, $count);
        $this->assertDatabaseCount('education_institutions', 26);
        $this->assertSame(['PLE'], EducationInstitution::query()->where('source_id', '1')->firstOrFail()->qualification_levels);
        $this->assertSame(['UCE', 'UACE'], EducationInstitution::query()->where('source_id', '26')->firstOrFail()->qualification_levels);
        $this->assertCount(3, $progressUpdates);
        Http::assertSentCount(5);
    }

    public function test_long_emis_sync_refreshes_its_session_before_high_page_offsets(): void
    {
        config(['erecruit.institution_directory.emis_sync_page_size' => 24]);
        $initialSnapshot = htmlspecialchars(json_encode(['data' => []], JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_HTML5);
        $initialPage = "<div wire:snapshot=\"{$initialSnapshot}\" data-csrf=\"csrf-token\"></div>";
        $cards = fn (int $start, int $end, string $type): string => collect(range($start, $end))->map(fn (int $index): string => <<<HTML
            <div class="card school-card" wire:click="showSchoolDetails({$index})">
                <span class="title text-dark">Official {$type} School {$index}</span>
                <div class="detail-item"><span class="text-soft">KUMI</span></div>
                <div class="detail-item"><span class="text-soft">{$type} SCHOOL</span></div>
                <span class="badge">ACTIVE</span>
            </div>
            HTML)->implode('');

        $getCount = 0;
        $currentType = '2';
        Http::fake(function (Request $request) use ($initialPage, $cards, &$getCount, &$currentType) {
            if ($request->method() === 'GET') {
                $getCount++;

                return Http::response($initialPage);
            }

            $payload = $request->data();
            $method = data_get($payload, 'components.0.calls.0.method');
            if ($method === 'performSchoolSearch') {
                $currentType = (string) data_get($payload, 'components.0.updates.school_type_id', $currentType);
            }
            $page = $method === 'schoolGoToPage'
                ? (int) data_get($payload, 'components.0.calls.0.params.0', 1)
                : 1;
            $total = $currentType === '2' ? 241 : 1;
            $start = $currentType === '2' ? (($page - 1) * 24) + 1 : 242;
            $end = $currentType === '2' ? min($start + 23, $total) : 242;

            return Http::response(['components' => [[
                'snapshot' => json_encode(['data' => [
                    'schoolTotalResults' => $total,
                    'schoolCurrentPage' => $page,
                ]], JSON_THROW_ON_ERROR),
                'effects' => ['html' => $cards($start, $end, $currentType === '2' ? 'PRIMARY' : 'SECONDARY')],
            ]]]);
        });

        $progressUpdates = [];
        $count = app(OfficialEducationInstitutionDirectory::class)->syncSchools(
            function (...$arguments) use (&$progressUpdates): void {
                $progressUpdates[] = $arguments;
            },
        );

        $this->assertSame(242, $count);
        $this->assertDatabaseCount('education_institutions', 242);
        $this->assertSame(['PLE'], EducationInstitution::query()->where('source_id', '241')->firstOrFail()->qualification_levels);
        $this->assertSame(['UCE', 'UACE'], EducationInstitution::query()->where('source_id', '242')->firstOrFail()->qualification_levels);
        $this->assertSame('primary / Uganda', $progressUpdates[0][0]);
        $this->assertCount(12, $progressUpdates);
        $this->assertSame(3, $getCount);
        Http::assertSentCount(16);
    }
}
