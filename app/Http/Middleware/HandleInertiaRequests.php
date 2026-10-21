<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        $user = $request->user();

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
            'auth' => [
                'user' => $user,
                'business' => $user?->currentBusiness?->only('id', 'name'),
                'plan' => fn () => $user?->currentBusiness ? [
                    'name' => $user->currentBusiness->plan()->name,
                    'trial_ends_at' => $user->currentBusiness->isOnFreeTrial() ? $user->currentBusiness->trial_ends_at->toDateString() : null,
                ] : null,
                'businesses' => $user?->businesses()->orderBy('name')->get(['businesses.id', 'businesses.name'])
                    ->map->only('id', 'name'),
            ],
        ]);
    }
}
