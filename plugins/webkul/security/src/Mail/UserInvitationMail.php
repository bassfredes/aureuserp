<?php

namespace Webkul\Security\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Webkul\Security\Models\Invitation;

class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    private Invitation $invitation;

    /**
     * Create a new message instance.
     */
    public function __construct(Invitation $invitation)
    {
        $this->invitation = $invitation;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('security::mail/user-invitation-mail.user-invitation.subject', [
                'app' => config('app.name'),
            ]),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'security::emails.user-invitation',
            with: [
                // 'token' rides along as a query parameter covered by the
                // signature (it isn't a route segment, see routes/web.php)
                // and is what AcceptInvitation re-validates at mutation
                // time, not just at this initial signed GET (#138 PR4
                // IDOR fix).
                'acceptUrl' => URL::signedRoute(
                    'security.invitation.accept',
                    [
                        'invitation' => $this->invitation,
                        'token'      => $this->invitation->token,
                    ]
                ),
            ]
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
