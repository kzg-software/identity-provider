<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\OauthConsent;
use App\Models\OauthToken;
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
}
