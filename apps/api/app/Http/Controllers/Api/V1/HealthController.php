<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'ups-erecruit-api',
            'version' => config('app.version', '1.0.0'),
            'time' => now()->toISOString(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn (): mixed => DB::selectOne('select 1 as ready')),
            'cache' => $this->check(function (): bool {
                Cache::put('health:ready', 'ok', 10);

                return Cache::get('health:ready') === 'ok';
            }),
            'storage' => $this->check(function (): bool {
                $disk = Storage::disk(config('filesystems.default'));
                $path = '.health/'.Str::uuid();
                $disk->put($path, 'ready');
                $ready = $disk->exists($path) && $disk->get($path) === 'ready';
                $disk->delete($path);

                return $ready;
            }),
        ];
        $ready = collect($checks)->every(fn (array $check): bool => $check['ok']);

        return response()->json(
            ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks],
            $ready ? 200 : 503,
        );
    }

    public function metrics(): JsonResponse
    {
        return response()->json([
            'observed_at' => now()->toISOString(),
            'queue' => [
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
            ],
            'documents' => DB::table('documents')->select('processing_status', DB::raw('count(*) as total'))
                ->groupBy('processing_status')->pluck('total', 'processing_status'),
            'offline' => [
                'active_packs' => DB::table('offline_packages')->where('status', 'active')->count(),
                'outstanding_events' => DB::table('offline_packages')->sum('outstanding_events'),
                'open_conflicts' => DB::table('sync_conflicts')->where('status', 'open')->count(),
            ],
            'notifications' => [
                'pending' => DB::table('notifications')->whereIn('status', ['pending', 'retrying'])->count(),
                'failed' => DB::table('notifications')->where('status', 'failed')->count(),
            ],
        ]);
    }

    /** @return array{ok: bool, error?: string} */
    private function check(callable $callback): array
    {
        try {
            $callback();

            return ['ok' => true];
        } catch (Throwable $exception) {
            return ['ok' => false, 'error' => class_basename($exception)];
        }
    }
}
