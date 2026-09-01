<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)
                        ->orWhere('username', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function show(User $user): View
    {
        $user->load('directory', 'sessions', 'oauthConsents.client');

        return view('admin.users.show', compact('user'));
    }

    public function create(): View
    {
        return view('admin.users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => 'required|string|max:255|unique:users,username',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'is_admin' => 'boolean',
        ]);

        $user = User::create([
            'username' => $data['username'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'name' => trim($data['first_name'].' '.$data['last_name']),
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'auth_source' => 'local',
            'is_admin' => $request->boolean('is_admin'),
            'is_active' => true,
        ]);

        AuditLog::record('admin.user_created', $request->user(), ['created_user_id' => $user->id]);

        return redirect()->route('admin.users.index')->with('status', 'Benutzer wurde erstellt.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Das eigene Konto kann nicht gelöscht werden.');
        }

        if ($user->isLocal() && $user->is_admin && ! $this->anotherLocalAdminExists($user)) {
            return back()->with('error', 'Der letzte lokale Administrator kann nicht gelöscht werden.');
        }

        AuditLog::record('admin.user_deleted', $request->user(), [
            'target_user_id' => $user->id,
            'auth_source' => $user->auth_source,
        ]);

        // Verzeichnis-Datensatz und Gruppen-Zuordnungen mitnehmen.
        $directoryUser = $user->directoryUser;
        if ($directoryUser) {
            $directoryUser->groups()->detach();
            $directoryUser->delete();
        }

        $user->delete();

        $note = $user->isLocal()
            ? 'Benutzer wurde gelöscht.'
            : 'Benutzer wurde entfernt. Liegt das Konto weiter im Verzeichnis, kann die nächste Synchronisierung es erneut anlegen.';

        return redirect()->route('admin.users.index')->with('status', $note);
    }

    private function anotherLocalAdminExists(User $exclude): bool
    {
        return User::where('auth_source', 'local')
            ->where('is_admin', true)
            ->where('is_active', true)
            ->where('id', '!=', $exclude->id)
            ->exists();
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        if ($user->auth_source !== 'local') {
            abort(403, 'Nur lokale Benutzer haben ein Passwort.');
        }

        $data = $request->validate(['password' => 'required|string|min:8|confirmed']);
        $user->update(['password' => Hash::make($data['password'])]);

        AuditLog::record('admin.password_reset', $request->user(), ['target_user_id' => $user->id]);

        return back()->with('status', 'Passwort wurde zurückgesetzt.');
    }
}
