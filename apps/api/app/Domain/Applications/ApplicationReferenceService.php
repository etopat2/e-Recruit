<?php

namespace App\Domain\Applications;

use App\Models\Application;
use DomainException;
use Illuminate\Support\Facades\DB;

class ApplicationReferenceService
{
    public function allocate(Application $application): string
    {
        if ($application->reference !== null) {
            return $application->reference;
        }

        if ($application->status !== Application::StatusDraft || $application->submitted_at !== null) {
            throw new DomainException('A final reference can only be allocated to an unsubmitted draft.');
        }

        return DB::transaction(function () use ($application): string {
            $lockedApplication = Application::query()->lockForUpdate()->findOrFail($application->id);
            if ($lockedApplication->reference !== null) {
                return $lockedApplication->reference;
            }

            $lockedApplication->loadMissing('post.campaign');
            DB::table('reference_sequences')->insertOrIgnore([
                'recruitment_post_id' => $lockedApplication->recruitment_post_id,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('reference_sequences')
                ->where('recruitment_post_id', $lockedApplication->recruitment_post_id)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                throw new DomainException('The application reference sequence could not be initialised.');
            }

            $number = (int) $sequence->next_value;
            DB::table('reference_sequences')
                ->where('recruitment_post_id', $lockedApplication->recruitment_post_id)
                ->update(['next_value' => $number + 1, 'updated_at' => now()]);

            $reference = strtr((string) config('erecruit.reference_pattern'), [
                '{year}' => (string) $lockedApplication->post->campaign->year,
                '{post}' => mb_strtoupper($lockedApplication->post->reference_prefix),
                '{sequence}' => str_pad(
                    (string) $number,
                    (int) config('erecruit.reference_digits'),
                    '0',
                    STR_PAD_LEFT,
                ),
            ]);

            $lockedApplication->forceFill(['reference' => $reference])->save();
            $application->setAttribute('reference', $reference);

            return $reference;
        }, 3);
    }
}
