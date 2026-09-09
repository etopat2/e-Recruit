<?php

namespace App\Services;

use App\Models\EducationInstitution;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OfficialEducationInstitutionDirectory
{
    /** @return array{nche: int, tvet: int} */
    public function syncCurrentDirectories(): array
    {
        return [
            'nche' => $this->syncHigherEducation(),
            'tvet' => $this->syncTvet(),
        ];
    }

    public function syncHigherEducation(): int
    {
        $url = (string) config('erecruit.institution_directory.nche_institutions_url');
        $html = $this->client()->get($url)->throw()->body();
        $xpath = $this->xpath($html);
        $rows = $xpath->query("//table[@id='unche-table']//tbody/tr");

        if (! $rows || $rows->count() === 0) {
            throw new RuntimeException('The NCHE institution directory did not contain any readable records.');
        }

        $sourceIds = [];
        foreach ($rows as $row) {
            $cells = $xpath->query('./td', $row);
            if (! $cells || $cells->count() < 5) {
                continue;
            }

            $name = $this->text($cells->item(0));
            $institutionType = $this->text($cells->item(1));
            $registrationStatus = $this->text($cells->item(2));
            $district = $this->text($cells->item(3));
            if ($name === '') {
                continue;
            }

            $sourceId = hash('sha256', $this->normalize($name));
            $sourceIds[] = $sourceId;
            $this->upsert([
                'source' => 'nche',
                'source_id' => $sourceId,
                'name' => $name,
                'institution_type' => $institutionType,
                'district' => $district ?: null,
                'registration_number' => null,
                'registration_status' => $registrationStatus,
                'operational_status' => 'Listed',
                'qualification_levels' => config('erecruit.institution_directory.higher_education_levels'),
                'source_url' => $url,
                'source_payload' => ['programmes_count' => (int) $this->text($cells->item(4))],
                'active' => true,
            ]);
        }

        $this->deactivateMissing('nche', $sourceIds);

        return count($sourceIds);
    }

    public function syncTvet(): int
    {
        $pageUrl = (string) config('erecruit.institution_directory.tvet_institutions_url');
        $directoryUrl = (string) config('erecruit.institution_directory.tvet_directory_url');
        $cookies = new CookieJar;
        $client = $this->client($cookies);
        $client->get($pageUrl)->throw();

        $response = $client
            ->withHeaders([
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Referer' => $pageUrl,
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->get($directoryUrl, $this->tvetQuery())
            ->throw();
        $rows = $response->json('data');

        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException('The MoES TVET institution directory did not contain any readable records.');
        }

        $sourceIds = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                continue;
            }

            $name = $this->plainText($row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $sourceId = (string) $row['id'];
            $sourceIds[] = $sourceId;
            $instituteStatus = $this->plainText($row['institute_status'] ?? '');
            $approvalStatus = $this->plainText($row['status'] ?? '');
            $registrationStatus = trim(implode(' / ', array_filter([$instituteStatus, $approvalStatus])));
            $operationalStatus = $this->plainText($row['is_enabled'] ?? '');
            $isRegistered = Str::lower($instituteStatus) === 'registered'
                && Str::contains(Str::lower($approvalStatus), 'approved');
            $isActive = Str::lower($operationalStatus) === 'active';

            $this->upsert([
                'source' => 'moes_tvet',
                'source_id' => $sourceId,
                'name' => $name,
                'institution_type' => $this->plainText($row['institute_category'] ?? ''),
                'district' => $this->plainText($row['district'] ?? '') ?: null,
                'registration_number' => $this->plainText($row['registration_no'] ?? '') ?: null,
                'registration_status' => $registrationStatus ?: null,
                'operational_status' => $operationalStatus ?: null,
                'qualification_levels' => config('erecruit.institution_directory.tvet_levels'),
                'source_url' => $pageUrl,
                'source_payload' => [
                    'institute_code' => $this->plainText($row['institute_code'] ?? ''),
                    'accredited' => Str::lower($this->plainText($row['is_accreditated'] ?? '')) === 'yes',
                    'license_number' => $this->plainText($row['license_no'] ?? ''),
                    'license_expiry' => $this->plainText($row['expiry_date'] ?? ''),
                ],
                'active' => $isRegistered && $isActive,
            ]);
        }

        $this->deactivateMissing('moes_tvet', $sourceIds);

        return count($sourceIds);
    }

    public function refreshSchoolMatches(string $level, string $search): int
    {
        $schoolTypeId = match ($level) {
            'PLE' => '2',
            'UCE', 'UACE' => '3',
            default => null,
        };
        if ($schoolTypeId === null) {
            return 0;
        }

        $cacheKey = 'institution-directory:emis:'.hash('sha256', $schoolTypeId.'|'.$this->normalize($search));
        if (Cache::has($cacheKey)) {
            return 0;
        }

        try {
            $count = $this->fetchEmisSchoolMatches($schoolTypeId, $search);
            Cache::put(
                $cacheKey,
                true,
                now()->addHours((int) config('erecruit.institution_directory.search_cache_hours')),
            );

            return $count;
        } catch (Throwable $exception) {
            Log::warning('Official EMIS institution search was unavailable.', [
                'level' => $level,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    private function fetchEmisSchoolMatches(string $schoolTypeId, string $search): int
    {
        $url = (string) config('erecruit.institution_directory.emis_search_url');
        $updateUrl = (string) parse_url($url, PHP_URL_SCHEME).'://'.(string) parse_url($url, PHP_URL_HOST).'/livewire/update';
        $cookies = new CookieJar;
        $client = $this->client($cookies);
        $page = $client->get($url)->throw()->body();

        if (! preg_match('/wire:snapshot="([^"]+)"/', $page, $snapshotMatch)
            || ! preg_match('/data-csrf="([^"]+)"/', $page, $csrfMatch)) {
            throw new RuntimeException('The EMIS public-search session could not be initialized.');
        }

        $snapshot = html_entity_decode($snapshotMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $csrf = $csrfMatch[1];
        $response = $client
            ->withHeaders([
                'Accept' => 'application/json',
                'Referer' => $url,
                'X-CSRF-TOKEN' => $csrf,
                'X-Livewire' => 'true',
            ])
            ->post($updateUrl, [
                '_token' => $csrf,
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => [
                        'schoolSearch' => trim($search),
                        'school_type_id' => $schoolTypeId,
                        'schoolPerPage' => (int) config('erecruit.institution_directory.maximum_results'),
                    ],
                    'calls' => [[
                        'path' => '',
                        'method' => 'performSchoolSearch',
                        'params' => [],
                    ]],
                ]],
            ])
            ->throw();
        $html = $response->json('components.0.effects.html');
        if (! is_string($html)) {
            throw new RuntimeException('The EMIS public-search response could not be read.');
        }

        $xpath = $this->xpath($html);
        $cards = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' school-card ')]");
        if (! $cards) {
            return 0;
        }

        $qualificationLevels = $schoolTypeId === '2' ? ['PLE'] : ['UCE', 'UACE'];
        $count = 0;
        foreach ($cards as $card) {
            if (! $card instanceof DOMElement
                || ! preg_match('/showSchoolDetails\((\d+)\)/', $card->getAttribute('wire:click'), $idMatch)) {
                continue;
            }

            $name = $this->text($xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' title ')]", $card)?->item(0));
            $details = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' detail-item ')]//span", $card);
            $status = $this->text($xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' badge ')]", $card)?->item(0));
            if ($name === '') {
                continue;
            }

            $this->upsert([
                'source' => 'moes_emis',
                'source_id' => $idMatch[1],
                'name' => $name,
                'institution_type' => $this->text($details?->item(1)) ?: ($schoolTypeId === '2' ? 'PRIMARY SCHOOL' : 'SECONDARY SCHOOL'),
                'district' => $this->text($details?->item(0)) ?: null,
                'registration_number' => null,
                'registration_status' => 'MoES EMIS record',
                'operational_status' => $status ?: null,
                'qualification_levels' => $qualificationLevels,
                'source_url' => $url,
                'source_payload' => ['school_type_id' => (int) $schoolTypeId],
                'active' => Str::upper($status) === 'ACTIVE',
            ]);
            $count++;
        }

        return $count;
    }

    /** @param array<string, mixed> $attributes */
    private function upsert(array $attributes): EducationInstitution
    {
        return EducationInstitution::query()->updateOrCreate(
            ['source' => $attributes['source'], 'source_id' => $attributes['source_id']],
            [
                ...$attributes,
                'normalized_name' => $this->normalize((string) $attributes['name']),
                'last_verified_at' => now(),
            ],
        );
    }

    /** @param list<string> $sourceIds */
    private function deactivateMissing(string $source, array $sourceIds): void
    {
        if ($sourceIds === []) {
            return;
        }

        EducationInstitution::query()
            ->where('source', $source)
            ->whereNotIn('source_id', $sourceIds)
            ->update(['active' => false]);
    }

    /** @return array<string, mixed> */
    private function tvetQuery(): array
    {
        $notOrderable = ['logo', 'domain_id', 'is_license_expired', 'is_enabled'];
        $columns = collect([
            'name', 'institute_code', 'registration_date', 'registration_no', 'institute_category',
            'coe_id', 'institute_type', 'institute_status', 'status', 'postal_code', 'country_id',
            'region', 'district', 'county', 'subcounty', 'parish', 'village', 'coordinates',
            'created_at', 'phone_code', 'phone', 'image', 'email', 'website', 'logo',
            'is_external_domain', 'external_domain_url', 'domain_id', 'sector_id', 'license_no',
            'licensed_date', 'is_license_expired', 'renewal_date', 'expiry_date', 'is_enabled',
        ])->map(function (string $name) use ($notOrderable): array {
            $column = [
                'data' => $name,
                'name' => $name,
                'search' => ['value' => '', 'regex' => false],
            ];
            if (in_array($name, $notOrderable, true)) {
                $column['orderable'] = 0;
            }

            return $column;
        })->all();

        return [
            'draw' => 1,
            'columns' => base64_encode(json_encode($columns, JSON_THROW_ON_ERROR)),
            'order' => [['column' => 1, 'dir' => 'desc']],
            'start' => 0,
            'length' => -1,
            'search' => ['value' => '', 'regex' => false],
        ];
    }

    private function client(?CookieJar $cookies = null): PendingRequest
    {
        $request = Http::timeout((int) config('erecruit.institution_directory.request_timeout_seconds'))
            ->withUserAgent('UPS-e-Recruit/1.0 institution-directory');

        return $cookies ? $request->withOptions(['cookies' => $cookies]) : $request;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function text(?DOMNode $node): string
    {
        return $node ? $this->plainText($node->textContent) : '';
    }

    private function plainText(mixed $value): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function normalize(string $value): string
    {
        return Str::upper(trim((string) preg_replace('/\s+/u', ' ', Str::ascii($value))));
    }
}
