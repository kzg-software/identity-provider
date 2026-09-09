<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\OauthConsent;
use App\Models\OauthToken;
use App\Support\MailSettings;
use App\Support\NotificationCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $consentClientIds = OauthConsent::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->pluck('oauth_client_id');

        $tokenClientIds = OauthToken::query()
            ->where('user_id', $user->id)
            ->where('revoked', false)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('oauth_client_id');

        return view('profile.index', [
            'user' => $user,
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            'passkeyCount' => $user->webauthnCredentials()->count(),
            'connectedAppCount' => $consentClientIds->merge($tokenClientIds)->unique()->count(),
            'activeSessionCount' => $user->sessions()->active()->count(),
        ]);
    }

    public function notifications(Request $request): View
    {
        $user = $request->user();

        $categories = collect(NotificationCategories::all())
            ->reject(fn ($c) => $c['admin'] && ! $user->is_admin);

        return view('profile.notifications', [
            'user' => $user,
            'categories' => $categories,
            'mailConfigured' => MailSettings::configured(),
        ]);
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        $user = $request->user();
        $prefs = $user->notification_email_prefs ?? [];

        foreach (NotificationCategories::adjustableKeys() as $key) {
            // Nur Kategorien speichern, die auf dieser Seite sichtbar waren.
            if (NotificationCategories::all()[$key]['admin'] && ! $user->is_admin) {
                continue;
            }
            $prefs[$key] = $request->boolean('categories.'.$key);
        }

        $user->forceFill(['notification_email_prefs' => $prefs])->save();

        return back()->with('status', 'Benachrichtigungseinstellungen gespeichert.');
    }
}
