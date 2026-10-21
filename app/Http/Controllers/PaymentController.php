<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\Document;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    /**
     * Record a payment against an invoice. The invoice is marked paid once the total is covered.
     */
    public function store(Request $request, Document $document): RedirectResponse
    {
        abort_unless($document->type === DocumentType::Invoice, 404);
        abort_if(in_array($document->status, [DocumentStatus::Void, DocumentStatus::Paid], true), 403);

        $balance = $document->balanceDue();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:'.($balance / 100)],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', Rule::in(array_map(fn ($method) => $method->value, PaymentMethod::manual()))],
            'reference' => ['nullable', 'string', 'max:255'],
        ], [
            'amount.max' => 'That is more than the '.Money::format($balance, $document->currency).' still owed.',
        ]);

        DB::transaction(function () use ($document, $validated) {
            $payment = new Payment([...$validated, 'amount' => Money::toPence($validated['amount'])]);
            $document->payments()->save($payment);
            $document->refreshPayments();
        });

        return back();
    }

    public function destroy(Document $document, Payment $payment): RedirectResponse
    {
        abort_unless($payment->document_id === $document->id, 404);

        DB::transaction(function () use ($document, $payment) {
            $payment->delete();
            $document->refreshPayments();
        });

        return back();
    }
}
