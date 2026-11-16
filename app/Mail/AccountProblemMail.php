<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells a person their Claude account needs attention.
 */
class AccountProblemMail extends Mailable
{
    public function __construct(public string $notice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Claude account needs attention');
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->notice)));
    }
}
