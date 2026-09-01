<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\UserSession;
use App\Services\SessionTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function index(Request $request): View
    {
        $sessions = $request->user()->sessions()
            ->active()
            ->orderByDesc('last_activity_at')
            ->get();

        return view('profile.sessions', [
            'sessions' => $sessions,
            'currentSessionId' => $request->session()->getId(),
        ]);
    }

    public function destroy(Request $request, UserSession $userSession, SessionTracker $tracker): RedirectResponse
    {
        abort_unless($userSession->user_id === $request->user()->id, 403);

        $tracker->revoke($userSession);

        AuditLog::record('user.session_revoked', $request->user(), ['session_id' => $userSession->session_id]);

        return back()->with('status', 'Sitzung wurde beendet.');
    }

    public function destroyOthers(Request $request, SessionTracker $tracker): RedirectResponse
    {
        $tracker->revokeAllForUserExcept($request->user(), $request->session()->getId());

        AuditLog::record('user.sessions_revoked_others', $request->user());

        return back()->with('status', 'Alle anderen Sitzungen wurden abgemeldet.');
    }
}
