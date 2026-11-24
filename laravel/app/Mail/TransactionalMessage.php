<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * رسالة جاهزة: الموضوع والنص مُجهَّزان قبل الطابور
 * حتى لا يغيّر تعديل القالب رسالةً خرجت للتو.
 */
class TransactionalMessage extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $htmlBody,
        public string $messageLocale,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.transactional',
            with: [
                'htmlBody' => $this->htmlBody,
                'dir' => $this->messageLocale === 'ar' ? 'rtl' : 'ltr',
                'lang' => $this->messageLocale === 'en' ? 'en' : 'ar',
            ],
        );
    }
}
