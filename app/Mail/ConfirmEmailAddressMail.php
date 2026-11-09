<?php

namespace App\Mail;

use App\Models\UserEmailAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConfirmEmailAddressMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public UserEmailAddress $emailAddress,
        public string $confirmationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm this address for Yak');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.confirm-email-address',
            text: 'mail.confirm-email-address-text',
            with: [
                'email' => $this->emailAddress->email,
                'ownerName' => $this->emailAddress->user->name,
                'confirmationUrl' => $this->confirmationUrl,
            ],
        );
    }
}
