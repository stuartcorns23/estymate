<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Payment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $unpaid = Document::ofType(DocumentType::Invoice)->where('status', DocumentStatus::Sent);
        $overdue = (clone $unpaid)->whereDate('due_date', '<', today());

        $summary = fn ($query) => $query
            ->with('client:id,name')
            ->limit(5)
            ->get()
            ->map(fn (Document $document) => [
                'id' => $document->id,
                'number' => $document->number,
                'client_name' => $document->client?->name,
                'date' => ($document->due_date ?? $document->issue_date)->toDateString(),
                'amount' => $document->type === DocumentType::Invoice ? $document->balanceDue() : $document->total,
                'currency' => $document->currency,
            ]);

        return Inertia::render('dashboard', [
            'totals' => [
                'outstanding' => (int) (clone $unpaid)->selectRaw('coalesce(sum(total - amount_paid), 0) as owed')->value('owed'),
                'overdue' => (int) (clone $overdue)->selectRaw('coalesce(sum(total - amount_paid), 0) as owed')->value('owed'),
                'paid_this_month' => (int) Payment::where('paid_on', '>=', today()->startOfMonth())->sum('amount'),
                'drafts' => Document::ofType(DocumentType::Invoice)->where('status', DocumentStatus::Draft)->count(),
            ],
            'overdueInvoices' => $summary((clone $overdue)->orderBy('due_date')),
            'openEstimates' => $summary(Document::ofType(DocumentType::Estimate)
                ->whereIn('status', [DocumentStatus::Sent, DocumentStatus::Accepted])
                ->latest('issue_date')),
            'usage' => $request->user()->currentBusiness->usage(),
        ]);
    }
}
