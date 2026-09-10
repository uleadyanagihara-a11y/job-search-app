<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $token  本文に埋め込む照合用トークン（Mailpit 側で受信確認に使う）
     */
    public function __construct(
        public string $subjectLine,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.test',
            with: [
                'token' => $this->token,
            ],
        );
    }
}
