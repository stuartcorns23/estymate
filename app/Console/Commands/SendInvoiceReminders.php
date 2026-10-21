<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Mail\InvoiceReminderMail;
use App\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendInvoiceReminders extends Command
{
    /**
     * Reminders go out the day after the due date, then weekly, up to this many.
     */
    public const MAX_REMINDERS = 3;

    public const DAYS_BETWEEN_REMINDERS = 7;

    protected $signature = 'invoices:send-reminders';

    protected $description = 'Email clients about overdue invoices';

    public function handle(): int
    {
        $sent = 0;

        Document::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Sent)
            ->whereDate('due_date', '<', today())
            ->where('reminders_sent', '<', self::MAX_REMINDERS)
            ->where(fn ($query) => $query
                ->whereNull('last_reminder_at')
                ->orWhere('last_reminder_at', '<=', now()->subDays(self::DAYS_BETWEEN_REMINDERS)))
            ->whereHas('business', fn ($query) => $query->where('send_reminders', true))
            ->whereHas('client', fn ($query) => $query->withoutGlobalScopes()->whereNotNull('email'))
            ->with(['business', 'client', 'items'])
            ->each(function (Document $invoice) use (&$sent) {
                Mail::to($invoice->client->email)->send(new InvoiceReminderMail($invoice));

                $invoice->reminders_sent++;
                $invoice->last_reminder_at = now();
                $invoice->save();
                $sent++;
            });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
