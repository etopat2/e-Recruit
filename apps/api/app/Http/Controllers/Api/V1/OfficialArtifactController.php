<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfficialArtifactController extends Controller
{
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:80']]);
        $application = Application::query()->where('reference', $data['reference'])->first();
        if ($application === null || $application->submitted_at === null) {
            return response()->json(['valid' => false, 'message' => 'No submitted UPS e-Recruit artefact matches this reference.'], 404);
        }

        return response()->json([
            'valid' => true,
            'issuer' => 'Uganda Prisons Service',
            'reference' => $application->reference,
            'artefact' => 'application_acknowledgement',
            'issued_at' => $application->submitted_at,
            'status' => $application->status,
        ]);
    }
}
