<?php

namespace App\Providers;

use App\Contracts\MalwareScanner;
use App\Models\AuditLog;
use App\Services\ClamAvMalwareScanner;
use App\Services\DevelopmentMalwareScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MalwareScanner::class, function (): MalwareScanner {
            if (config('erecruit.uploads.malware_scanner') === 'clamav') {
                return new ClamAvMalwareScanner(
                    (string) config('erecruit.uploads.clamav_host'),
                    (int) config('erecruit.uploads.clamav_port'),
                );
            }

            return new DevelopmentMalwareScanner;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        AuditLog::updating(fn (): never => throw new LogicException('Audit entries are append-only.'));
        AuditLog::deleting(fn (): never => throw new LogicException('Audit entries are append-only.'));

        RateLimiter::for('login', function (Request $request): Limit {
            $identity = Str::lower((string) $request->input('identity'));

            return Limit::perMinute(5)->by(Str::transliterate($identity.'|'.$request->ip()));
        });
        RateLimiter::for('registration', fn (Request $request): Limit => Limit::perHour(5)->by((string) $request->ip()));
        RateLimiter::for('otp', fn (Request $request): Limit => Limit::perMinute(5)->by((string) ($request->user()?->id ?: $request->ip())));
        RateLimiter::for('status-lookup', fn (Request $request): Limit => Limit::perMinute(3)->by((string) $request->ip()));
        RateLimiter::for('uploads', fn (Request $request): Limit => Limit::perMinute(20)->by((string) ($request->user()?->id ?: $request->ip())));
    }
}
