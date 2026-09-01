<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = DB::table('notifications')->where(function ($query) use ($request): void {
            $query->where(function ($portal) use ($request): void {
                $portal->where('channel', 'in_portal')->where('recipient', (string) $request->user()->id);
            })->orWhereIn('application_id', DB::table('applications')->join('applicants', 'applicants.id', '=', 'applications.applicant_id')->where('applicants.user_id', $request->user()->id)->select('applications.id'));
        })->select('id', 'application_id', 'event_code', 'channel', 'status', 'delivered_at', 'read_at', 'created_at')->latest()->paginate(50);

        return response()->json(['notifications' => $notifications]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $updated = DB::table('notifications')->where('id', $notification)->where('channel', 'in_portal')->where('recipient', (string) $request->user()->id)->update(['read_at' => now(), 'updated_at' => now()]);
        abort_if($updated === 0, 404);

        return response()->json(['status' => 'read']);
    }

    public function pushConfig(): JsonResponse
    {
        return response()->json([
            'enabled' => filled(config('services.push.public_key')) && filled(config('services.push.base_url')),
            'public_key' => config('services.push.public_key'),
        ]);
    }

    public function subscribe(Request $request, AuditService $audit): JsonResponse
    {
        abort_unless(filled(config('services.push.public_key')) && filled(config('services.push.base_url')), 503, 'Push notifications are not configured.');
        $data = $request->validate([
            'endpoint' => ['required', 'url:http,https', 'max:2048'],
            'keys.p256dh' => ['required', 'string', 'max:512'],
            'keys.auth' => ['required', 'string', 'max:512'],
            'content_encoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);
        $hash = hash('sha256', $data['endpoint']);
        $existing = PushSubscription::query()->where('endpoint_hash', $hash)->first();
        abort_if($existing !== null && (int) $existing->user_id !== (int) $request->user()->id, 409, 'This push endpoint is already registered to another account.');
        $subscription = $existing ?? new PushSubscription(['user_id' => $request->user()->id, 'endpoint_hash' => $hash]);
        $subscription->fill([
            'endpoint_encrypted' => $data['endpoint'],
            'public_key_encrypted' => data_get($data, 'keys.p256dh'),
            'auth_token_encrypted' => data_get($data, 'keys.auth'),
            'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'revoked_at' => null,
        ])->save();
        $audit->record('notification.push_subscribed', $subscription, actor: $request->user(), after: ['endpoint_hash' => $hash]);

        return response()->json(['subscription_id' => $subscription->id], $existing === null ? 201 : 200);
    }

    public function unsubscribe(Request $request, PushSubscription $pushSubscription, AuditService $audit): JsonResponse
    {
        abort_unless((int) $pushSubscription->user_id === (int) $request->user()->id, 403);
        $pushSubscription->forceFill(['revoked_at' => now()])->save();
        $audit->record('notification.push_unsubscribed', $pushSubscription, actor: $request->user(), after: ['revoked_at' => $pushSubscription->revoked_at]);

        return response()->json(['status' => 'revoked']);
    }
}
