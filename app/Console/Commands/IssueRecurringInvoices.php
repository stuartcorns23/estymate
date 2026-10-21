<?php

namespace App\Console\Commands;

use App\Actions\IssueRecurringInvoice;
use App\Models\RecurringInvoice;
use Illuminate\Console\Command;

class IssueRecurringInvoices extends Command
{
    protected $signature = 'invoices:issue-recurring';

    protected $description = 'Create invoices from schedules that are due';

    public function handle(IssueRecurringInvoice $issue): int
    {
        $issued = 0;

        RecurringInvoice::withoutGlobalScopes()->due()->each(function (RecurringInvoice $schedule) use ($issue, &$issued) {
            // Catch up if the scheduler was down, but never issue future invoices early.
            while ($schedule->is_active && $schedule->next_issue_date->lte(today())
                && ($schedule->ends_on === null || $schedule->next_issue_date->lte($schedule->ends_on))) {
                $issue->handle($schedule);
                $issued++;
            }
        });

        $this->info("Issued {$issued} invoice(s).");

        return self::SUCCESS;
    }
}
