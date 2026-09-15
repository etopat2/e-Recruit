<?php

namespace App\Services;

use App\Models\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationRoutingService
{
    /**
     * Resolve the LC1-supported district from the canonical application draft.
     *
     * @return array{address_type: ?string, district_id: ?string}
     */
    public function resolveDraft(Application $application): array
    {
        $draft = $application->draft_data ?? [];
        $addresses = collect([
            'origin' => data_get($draft, 'origin.district_id'),
            'residence' => data_get($draft, 'residence.district_id') ?: data_get($draft, 'address.district_id'),
        ])->filter(fn (mixed $districtId): bool => is_string($districtId) && $districtId !== '');

        return $this->resolve(
            (string) $application->post->lc_source_policy,
            $addresses->all(),
            data_get($draft, 'lc1_letter.address_type'),
        );
    }

    /**
     * Resolve and persist routing for a submitted legacy application.
     *
     * @return array{address_type: string, district_id: string}
     */
    public function resolvePersisted(Application $application): array
    {
        if ($application->routing_address_type !== null && $application->routing_district_id !== null) {
            return [
                'address_type' => $application->routing_address_type,
                'district_id' => $application->routing_district_id,
            ];
        }

        $application->loadMissing('post');
        $addresses = DB::table('applicant_addresses')
            ->where('application_id', $application->id)
            ->whereIn('address_type', ['origin', 'residence'])
            ->pluck('district_id', 'address_type')
            ->all();
        $routing = $this->resolve(
            (string) $application->post->lc_source_policy,
            $addresses,
            data_get($application->draft_data, 'lc1_letter.address_type'),
        );
        if ($routing['address_type'] === null || $routing['district_id'] === null) {
            throw ValidationException::withMessages([
                'routing' => 'This validated application has no LC1-supported routing district. Correct its origin or residence evidence before allocation.',
            ]);
        }

        $application->forceFill([
            'routing_address_type' => $routing['address_type'],
            'routing_district_id' => $routing['district_id'],
        ])->save();

        return ['address_type' => $routing['address_type'], 'district_id' => $routing['district_id']];
    }

    /**
     * @param  array<string, string>  $addresses
     * @return array{address_type: ?string, district_id: ?string}
     */
    private function resolve(string $policy, array $addresses, mixed $selectedAddressType): array
    {
        if ($addresses === []) {
            return ['address_type' => null, 'district_id' => null];
        }

        $requiredType = match ($policy) {
            'origin' => 'origin',
            'residence' => 'residence',
            default => is_string($selectedAddressType) ? $selectedAddressType : null,
        };
        if ($requiredType === null && count($addresses) === 1) {
            $requiredType = (string) array_key_first($addresses);
        }
        if (! in_array($requiredType, ['origin', 'residence'], true)) {
            throw ValidationException::withMessages([
                'draft_data.lc1_letter.address_type' => 'Choose whether the LC1 letter was issued for the place of origin or current residence.',
            ]);
        }
        if (! isset($addresses[$requiredType])) {
            throw ValidationException::withMessages([
                "draft_data.{$requiredType}.district_id" => "Complete the {$requiredType} address supported by the LC1 letter.",
            ]);
        }

        return ['address_type' => $requiredType, 'district_id' => $addresses[$requiredType]];
    }
}
