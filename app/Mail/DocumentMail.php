<?php

namespace App\Mail;

use App\Models\Document;
use App\Support\DocumentPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Document $document, public string $body) {}

    public function envelope(): Envelope
    {
        $business = $this->document->business;

        return new Envelope(
            replyTo: $business->email ? [new Address($business->email, $business->name)] : [],
            subject: "{$this->document->type->label()} {$this->document->number} from {$business->name}",
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.document');
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => DocumentPdf::render($this->document), DocumentPdf::filename($this->document))
                ->withMime('application/pdf'),
        ];
    }
}
