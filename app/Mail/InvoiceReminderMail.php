<?php

namespace App\Mail;

use App\Models\Document;
use App\Support\DocumentPdf;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Document $document) {}

    public function envelope(): Envelope
    {
        $business = $this->document->business;

        return new Envelope(
            replyTo: $business->email ? [new Address($business->email, $business->name)] : [],
            subject: "Reminder: invoice {$this->document->number} from {$business->name} is overdue",
        );
    }

    public function content(): Content
    {
        $document = $this->document;
        $greeting = $document->client->contact_name ? "Hello {$document->client->contact_name}," : 'Hello,';

        return new Content(text: 'mail.document', with: [
            'body' => implode("\n", [
                $greeting,
                '',
                "This is a friendly reminder that invoice {$document->number} was due on {$document->due_date->format('j F Y')}"
                    .' and '.Money::format($document->balanceDue(), $document->currency).' is still outstanding.',
                '',
                'If you have already paid, thank you, and please ignore this email. A copy of the invoice is attached.',
                '',
                'Thank you,',
                $document->business->name,
            ]),
        ]);
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
