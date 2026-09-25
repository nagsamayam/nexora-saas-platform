<?php

declare(strict_types=1);

namespace App\Mail\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public readonly string $userName,
        public readonly string $userEmail,
        public readonly string $tenantName,
        public readonly string $tenantSlug,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Tenant Approved - %s', $this->tenantName),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                '<h1>Tenant Approved</h1><p>Hello %s,</p><p>Your tenant <strong>%s</strong> (slug: <code>%s</code>) has been approved by the platform administrator and is now being provisioned.</p>',
                e($this->userName),
                e($this->tenantName),
                e($this->tenantSlug),
            ),
        );
    }
}
