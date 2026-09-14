<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MfaEmailCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your UPS e-Recruit security code');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.mfa-email-code',
            text: 'mail.mfa-email-code-text',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
