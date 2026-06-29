<?php

namespace App\Mail;

use App\Models\SystemAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SystemAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly SystemAlert $alert) {}

    public function envelope(): Envelope
    {
        $severityPrefix = match ($this->alert->severity) {
            SystemAlert::SEVERITY_CRITICAL => '[CRITICAL]',
            SystemAlert::SEVERITY_WARNING  => '[WARNING]',
            default                        => '[INFO]',
        };
        return new Envelope(
            subject: "{$severityPrefix} {$this->alert->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.system-alert',
            with: [
                'alert' => $this->alert,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
