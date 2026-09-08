<?php

declare(strict_types=1);

namespace App\Mail\Tenancy;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenantProvisionedMail extends Mailable
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
            subject: sprintf('Tenant Provisioned Successfully - %s', $this->tenantName),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                '<h1>Tenant Ready!</h1><p>Hello %s,</p><p>Great news! Your tenant <strong>%s</strong> (slug: <code>%s</code>) has been successfully provisioned and is now active and ready to use.</p>',
                e($this->userName),
                e($this->tenantName),
                e($this->tenantSlug),
            ),
        );
    }
}
