<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value();

        return Inertia::render('clients/index', [
            'clients' => Client::query()
                ->when($search, fn ($query) => $query->where(fn ($query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('contact_name', 'like', "%{$search}%")))
                ->withCount('documents')
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'filters' => ['search' => $search],
        ]);
    }

    public function create(Request $request): Response
    {
        $request->user()->currentBusiness->ensureWithinLimit('clients');

        return Inertia::render('clients/form', ['client' => null]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        $request->user()->currentBusiness->ensureWithinLimit('clients');

        Client::create($request->validated());

        return to_route('clients.index');
    }

    public function edit(Client $client): Response
    {
        return Inertia::render('clients/form', ['client' => $client]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($request->validated());

        return to_route('clients.index');
    }

    public function destroy(Client $client): RedirectResponse
    {
        // Soft deleted, so existing estimates and invoices keep their client details.
        $client->delete();

        return to_route('clients.index');
    }
}
