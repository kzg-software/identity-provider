<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lets a local admin sign in as another user for support/debugging purposes
 * ("login as"). The impersonated user's own sessions/credentials are never
 * touched - they notice nothing. The admin sees a banner (see
 * layouts/admin.blade.php) that lets them return to their own account.
 */
class ImpersonateController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        if ($user->is($admin)) {
            return back()->with('error', 'Du kannst dich nicht selbst als Nutzer anmelden.');
        }

        if ($user->is_admin) {
            return back()->with('error', 'Anmelden als anderer Administrator ist nicht möglich.');
        }

        if (! $user->is_active) {
            return back()->with('error', 'Dieser Benutzer ist gesperrt.');
        }

        // Session::regenerate() would drop the flash data we still need for the
        // banner-less redirect target, but more importantly we must NOT touch
        // the target user's own state - only swap who the current session's
        // Auth guard resolves to, remembering the admin's id to switch back.
        AuditLog::record('admin.impersonate_start', $admin, ['target_user_id' => $user->id]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('impersonate.admin_id', $admin->id);

        return redirect()->route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull('impersonate.admin_id');

        if (! $adminId) {
            return redirect()->route('dashboard');
        }

        $admin = User::find($adminId);

        if (! $admin) {
            Auth::guard('web')->logout();

            return redirect()->route('login');
        }

        AuditLog::record('admin.impersonate_stop', $admin, ['returned_from_user_id' => $request->user()?->id]);

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.users.index');
    }
}
