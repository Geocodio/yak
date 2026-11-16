<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells a person an unattended event is waiting on a thread their computer owns.
 */
class HeldEventMail extends Mailable
{
    public function __construct(public string $notice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'An event is waiting on your computer');
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->notice)));
    }
}
