<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OauthClient;
use App\Models\OauthConsent;
use App\Models\OauthScope;
use App\Models\OauthToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ConnectedAppController extends Controller
{
    public function index(Request $request): View
    {
        $userId = $request->user()->id;

        $consents = OauthConsent::query()
            ->with('client.provider.application')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->get()
            ->keyBy('oauth_client_id');

        $tokenClientIds = OauthToken::query()
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('oauth_client_id')
            ->unique();

        $clientIds = $consents->keys()->merge($tokenClientIds)->unique()->values();

        $clients = OauthClient::query()
            ->with('provider.application')
            ->whereIn('id', $clientIds)
            ->get();

        $scopeLabels = OauthScope::query()->pluck('label', 'key');

        $apps = $clients->map(function (OauthClient $client) use ($consents, $scopeLabels) {
            $application = $client->provider?->application;
            $consent = $consents->get($client->id);
            $scopes = collect($consent?->scopes ?? [])
                ->map(fn ($key) => $scopeLabels[$key] ?? $key)
                ->values();

            return [
                'client_id' => $client->id,
                'name' => $application?->name ?: $client->name,
                'logo_url' => $application?->logo_path
                    ? Storage::disk('public')->url($application->logo_path)
                    : null,
                'scopes' => $scopes,
                'granted_at' => $consent?->granted_at,
                'remembered' => $consent !== null,
            ];
        })->sortBy('name')->values();

        return view('profile.apps', ['apps' => $apps]);
    }

    public function destroy(Request $request, OauthClient $client): RedirectResponse
    {
        $userId = $request->user()->id;

        OauthConsent::query()
            ->where('user_id', $userId)
            ->where('oauth_client_id', $client->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        OauthToken::query()
            ->where('user_id', $userId)
            ->where('oauth_client_id', $client->id)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        AuditLog::record('oauth.consent.revoked_by_user', $request->user(), [
            'client_id' => $client->client_id,
        ], $client->provider?->application);

        return back()->with('status', 'Zugriff wurde entzogen. Die Anwendung fragt beim nächsten Mal erneut.');
    }
}
