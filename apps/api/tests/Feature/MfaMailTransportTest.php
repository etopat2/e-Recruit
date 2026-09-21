<?php

namespace Tests\Feature;

use App\Jobs\DeliverMfaRecoveryCodesJob;
use App\Models\User;
use App\Services\OutboundMailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\RequiresEnvironmentVariable;
use Tests\TestCase;

#[RequiresEnvironmentVariable('RUN_MAIL_TRANSPORT_TEST', '1')]
class MfaMailTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_smtp_transports_otp_and_separate_recovery_email_to_local_capture(): void
    {
        // Opt-in Docker integration test; never uses the live users database or
        // an internet recipient, and never deletes other Mailpit messages.
        config()->set([
            'mail.default' => 'smtp', 'mail.delivery_mode' => 'capture',
            'mail.mailers.smtp.host' => 'mailpit', 'mail.mailers.smtp.port' => 1025,
            'mail.mailers.smtp.url' => null, 'mail.mailers.smtp.scheme' => 'smtp',
            'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null,
            'mail.from.address' => 'smoke@example.test',
        ]);
        Queue::fake([DeliverMfaRecoveryCodesJob::class]);
        $recipient = 'smtp-smoke-'.Str::uuid().'@example.test';
        $password = 'SyntheticMailSmoke2026'; // gitleaks:allow -- synthetic test credential
        $user = User::factory()->create([
            'email' => $recipient, 'password' => $password, 'is_privileged' => true,
            'user_type' => 'system_administrator', 'mfa_method' => null,
            'mfa_confirmed_at' => null, 'must_change_password' => false,
        ]);
        Sanctum::actingAs($user, ['mfa:enrol']);
        $messageIds = [];

        try {
            $enrolment = $this->postJson('/api/v1/auth/mfa/enrol', ['password' => $password, 'method' => 'email'])
                ->assertOk()->assertJsonPath('delivery_status', 'captured');
            $messages = Http::timeout(5)->get('http://mailpit:8025/api/v1/search', ['query' => 'to:'.$recipient])
                ->throw()->json('messages');
            $messageIds = array_column($messages, 'ID');
            $this->assertCount(1, $messages);
            $otp = Http::timeout(5)->get('http://mailpit:8025/api/v1/message/'.$messageIds[0])->throw()->json();
            $this->assertSame('Your UPS e-Recruit security code', $otp['Subject']);
            $this->assertSame(1, preg_match('/\b(\d{6})\b/', $otp['Text'], $matches));
            Queue::assertNotPushed(DeliverMfaRecoveryCodesJob::class);

            $this->postJson('/api/v1/auth/mfa/confirm', [
                'challenge_id' => $enrolment->json('challenge_id'),
                'challenge_token' => $enrolment->json('challenge_token'), 'code' => $matches[1],
            ])->assertOk()->assertJsonPath('recovery_email_status', 'queued');
            Queue::assertPushed(DeliverMfaRecoveryCodesJob::class, 1);
            Queue::pushed(DeliverMfaRecoveryCodesJob::class)->sole()->handle(app(OutboundMailService::class));

            $messages = Http::timeout(5)->get('http://mailpit:8025/api/v1/search', ['query' => 'to:'.$recipient])
                ->throw()->json('messages');
            $messageIds = array_column($messages, 'ID');
            $this->assertCount(2, $messages);
            $recovery = collect($messages)->firstWhere('Subject', 'Your UPS e-Recruit MFA recovery codes');
            $this->assertNotNull($recovery);
            $body = Http::timeout(5)->get('http://mailpit:8025/api/v1/message/'.$recovery['ID'])->throw()->json('Text');
            foreach ($enrolment->json('recovery_codes') as $code) {
                $this->assertStringContainsString($code, $body);
            }
            $this->assertDatabaseHas('notifications', ['recipient' => $recipient, 'event_code' => 'auth.mfa.email_code', 'status' => 'captured', 'delivered_at' => null]);
            $this->assertDatabaseHas('notifications', ['recipient' => $recipient, 'event_code' => 'auth.mfa.recovery_codes', 'status' => 'captured', 'delivered_at' => null]);
        } finally {
            if ($messageIds !== []) {
                Http::timeout(5)->delete('http://mailpit:8025/api/v1/messages', ['IDs' => $messageIds])->throw();
            }
        }
    }
}
