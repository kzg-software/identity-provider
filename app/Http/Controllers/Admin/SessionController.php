<?php

namespace App\Http\Controllers\Admin;

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
        $sessions = UserSession::with('user')
            ->active()
            ->orderByDesc('last_activity_at')
            ->paginate(25);

        return view('admin.sessions.index', ['sessions' => $sessions]);
    }

    public function destroy(Request $request, UserSession $userSession, SessionTracker $tracker): RedirectResponse
    {
        $tracker->revoke($userSession);

        AuditLog::record('admin.session_revoked', $request->user(), [
            'target_user_id' => $userSession->user_id,
            'session_id' => $userSession->session_id,
        ]);

        return back()->with('status', 'Sitzung wurde beendet.');
    }
}
