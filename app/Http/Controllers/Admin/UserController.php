<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SecuritySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'password' => ['required', 'string', 'confirmed', SecuritySettings::passwordRule()],
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
            'manual_roles' => $request->boolean('is_admin') ? ['admin'] : [],
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

    public function toggleAdmin(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Die eigenen Administrator-Rechte können nicht geändert werden.');
        }

        if ($user->hasRole('admin')) {
            if ($user->isLocal() && ! $this->anotherLocalAdminExists($user)) {
                return back()->with('error', 'Der letzte lokale Administrator kann nicht herabgestuft werden.');
            }

            $user->revokeManualRole('admin');
            $user->save();

            $message = $user->hasRole('admin')
                ? 'Die Administrator-Rolle kommt aus einem Gruppen-Mapping und bleibt bestehen. Passe dazu das Rollen-Mapping an.'
                : 'Administrator-Rechte entzogen.';
        } else {
            $user->grantManualRole('admin');
            $user->save();
            $message = 'Administrator-Rechte erteilt.';
        }

        AuditLog::record('admin.user_admin_toggled', $request->user(), [
            'target_user_id' => $user->id,
            'is_admin' => $user->fresh()->is_admin,
        ]);

        return back()->with('status', $message);
    }

    /**
     * GET /admin/users/export — alle (bzw. gesuchten) Benutzer als CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $users = User::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('username', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->orderBy('username')
            ->lazy(500);

        return response()->streamDownload(function () use ($users) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF");
            fputcsv($out, ['username', 'first_name', 'last_name', 'name', 'email', 'auth_source', 'is_admin', 'is_active', 'last_login_at']);

            foreach ($users as $u) {
                fputcsv($out, [
                    $u->username, $u->first_name, $u->last_name, $u->name, $u->email,
                    $u->auth_source, $u->is_admin ? '1' : '0', $u->is_active ? '1' : '0',
                    $u->last_login_at?->toIso8601String(),
                ]);
            }

            fclose($out);
        }, 'benutzer-'.now()->format('Y-m-d-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function importForm(): View
    {
        return view('admin.users.import');
    }

    /**
     * POST /admin/users/import — lokale Benutzer aus einer CSV anlegen oder
     * aktualisieren. Spalten (Kopfzeile, Reihenfolge egal): username*, email*,
     * first_name, last_name, name, is_admin, is_active, password.
     */
    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [
            'file.required' => 'Bitte eine CSV-Datei auswählen.',
            'file.mimes' => 'Die Datei muss eine CSV-Datei sein.',
        ]);

        $rows = $this->parseCsv($request->file('file')->getRealPath());

        if ($rows === null) {
            return back()->with('error', 'Die CSV hat keine gültige Kopfzeile (mindestens username und email).');
        }

        $created = 0;
        $updated = 0;
        $skipped = [];
        $generatedPasswords = [];
        $passwordRule = SecuritySettings::passwordRule();

        foreach ($rows as $i => $row) {
            $line = $i + 2; // +1 Kopfzeile, +1 auf 1 basiert
            $username = trim((string) ($row['username'] ?? ''));
            $email = trim((string) ($row['email'] ?? ''));

            if ($username === '' || $email === '') {
                $skipped[] = "Zeile {$line}: username oder email fehlt.";

                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped[] = "Zeile {$line}: {$email} ist keine gültige E-Mail-Adresse.";

                continue;
            }

            $existing = User::where('username', $username)->first();

            if ($existing && ! $existing->isLocal()) {
                $skipped[] = "Zeile {$line}: {$username} gehört zu einem Verzeichnis und wird nicht überschrieben.";

                continue;
            }

            $emailOwner = User::where('email', $email)->first();
            if ($emailOwner && (! $existing || $emailOwner->id !== $existing->id)) {
                $skipped[] = "Zeile {$line}: die E-Mail {$email} ist bereits vergeben.";

                continue;
            }

            $first = trim((string) ($row['first_name'] ?? ''));
            $last = trim((string) ($row['last_name'] ?? ''));
            $name = trim((string) ($row['name'] ?? trim($first.' '.$last))) ?: $username;
            $isAdmin = $this->boolFromCsv($row['is_admin'] ?? null);
            $isActive = array_key_exists('is_active', $row) ? $this->boolFromCsv($row['is_active']) : true;

            if ($existing) {
                $existing->fill([
                    'first_name' => $first ?: $existing->first_name,
                    'last_name' => $last ?: $existing->last_name,
                    'name' => $name,
                    'email' => $email,
                    'is_active' => $isActive,
                ]);
                $isAdmin ? $existing->grantManualRole('admin') : $existing->revokeManualRole('admin');
                $existing->save();
                $updated++;

                continue;
            }

            $password = trim((string) ($row['password'] ?? ''));

            if ($password !== '') {
                $check = validator(['password' => $password], ['password' => ['string', $passwordRule]]);
                if ($check->fails()) {
                    $skipped[] = "Zeile {$line}: Passwort erfüllt die Richtlinie nicht.";

                    continue;
                }
            } else {
                $password = Str::password(16, symbols: false);
                $generatedPasswords[$username] = $password;
            }

            User::create([
                'username' => $username,
                'first_name' => $first,
                'last_name' => $last,
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'auth_source' => 'local',
                'manual_roles' => $isAdmin ? ['admin'] : [],
                'is_active' => $isActive,
            ]);
            $created++;
        }

        AuditLog::record('admin.users_imported', $request->user(), [
            'created' => $created, 'updated' => $updated, 'skipped' => count($skipped),
        ]);

        return redirect()->route('admin.users.import')
            ->with('import_result', [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'generated' => $generatedPasswords,
            ]);
    }

    /**
     * POST /admin/users/bulk — Massenaktion auf mehrere Benutzer.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => 'required|in:lock,unlock,delete',
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $users = User::whereIn('id', $data['ids'])->get();
        $done = 0;
        $skipped = 0;

        foreach ($users as $user) {
            if ($user->id === $request->user()->id) {
                $skipped++;

                continue;
            }

            if (in_array($data['action'], ['lock', 'delete'], true)
                && $user->isLocal() && $user->is_admin && ! $this->anotherLocalAdminExists($user)) {
                $skipped++;

                continue;
            }

            if ($data['action'] === 'delete') {
                $directoryUser = $user->directoryUser;
                if ($directoryUser) {
                    $directoryUser->groups()->detach();
                    $directoryUser->delete();
                }
                $user->delete();
            } else {
                $user->update(['is_active' => $data['action'] === 'unlock']);
            }

            $done++;
        }

        AuditLog::record('admin.users_bulk_action', $request->user(), [
            'action' => $data['action'], 'done' => $done, 'skipped' => $skipped,
        ]);

        $verb = ['lock' => 'gesperrt', 'unlock' => 'entsperrt', 'delete' => 'gelöscht'][$data['action']];
        $note = "{$done} Benutzer {$verb}.";
        if ($skipped > 0) {
            $note .= " {$skipped} übersprungen (eigenes Konto oder letzter lokaler Administrator).";
        }

        return redirect()->route('admin.users.index')->with('status', $note);
    }

    /**
     * @return list<array<string, string>>|null null, wenn die Kopfzeile fehlt
     */
    private function parseCsv(string $path): ?array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            fclose($handle);

            return null;
        }

        // BOM am ersten Feld entfernen, alles klein.
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF")), $header);

        if (! in_array('username', $header, true) || ! in_array('email', $header, true)) {
            fclose($handle);

            return null;
        }

        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            if ($cells === [null] || $cells === []) {
                continue;
            }

            $row = [];
            foreach ($header as $idx => $key) {
                $row[$key] = $cells[$idx] ?? '';
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    private function boolFromCsv(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'ja', 'y', 'x'], true);
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        if ($user->auth_source !== 'local') {
            abort(403, 'Nur lokale Benutzer haben ein Passwort.');
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', SecuritySettings::passwordRule()],
        ]);
        $user->update(['password' => Hash::make($data['password'])]);

        AuditLog::record('admin.password_reset', $request->user(), ['target_user_id' => $user->id]);

        return back()->with('status', 'Passwort wurde zurückgesetzt.');
    }
}
