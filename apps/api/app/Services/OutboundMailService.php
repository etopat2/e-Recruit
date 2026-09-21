<?php

namespace App\Services;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class OutboundMailService
{
    /** @return array{provider: string, accepted: bool, status: string, message_id: ?string} */
    public function send(string $recipient, Mailable $mail): array
    {
        $mode = (string) config('mail.delivery_mode');
        $mailer = (string) config('mail.default');
        if (config("mail.mailers.{$mailer}.transport") !== 'smtp'
            || ! in_array($mode, ['capture', 'self_hosted', 'smtp'], true)
            || ($mode === 'capture' && ! App::environment(['local', 'testing']))) {
            throw new RuntimeException('Email requires a configured SMTP server; local capture is only permitted in development.');
        }
        $host = strtolower((string) config("mail.mailers.{$mailer}.host"));
        if ($mode !== 'capture' && ($host === 'mailpit' || $host === 'mailhog')) {
            throw new RuntimeException('A development mail catcher cannot deliver email to external inboxes.');
        }

        try {
            $sent = Mail::to($recipient)->send($mail);
            if ($sent === null && ! Mail::isFake()) {
                throw new RuntimeException('Mail submission was cancelled.');
            }
        } catch (Throwable) {
            // SMTP exceptions can contain message bodies, credentials or codes.
            // Do not chain the original exception into queue/log/API output.
            throw new RuntimeException('The email server did not accept the message. Check SMTP connectivity and the mail server logs.');
        }

        return [
            'provider' => $mode === 'self_hosted' ? 'self_hosted_smtp' : $mailer,
            'accepted' => true,
            'status' => $mode === 'capture' ? 'captured' : 'submitted',
            'message_id' => $sent?->getMessageId(),
        ];
    }
}
