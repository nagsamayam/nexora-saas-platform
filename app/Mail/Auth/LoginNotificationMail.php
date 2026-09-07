<?php

declare(strict_types=1);

namespace App\Mail\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public readonly string $userEmail,
        public readonly ?string $deviceName = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $loginTime = null,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Security Alert: New Login to Your Nexora Account',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                '<h1>New Login Detected</h1><p>A new login was recorded for your account (%s).</p><ul><li>Device: %s</li><li>IP Address: %s</li><li>Time: %s</li></ul><p>If this was not you, please immediately log out from all devices and reset your password.</p>',
                e($this->userEmail),
                e($this->deviceName ?? 'Unknown device'),
                e($this->ipAddress ?? 'Unknown IP'),
                e($this->loginTime ?? now()->toIso8601String()),
            ),
        );
    }
}
