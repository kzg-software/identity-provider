<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use App\Support\UploadLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class BackupController extends Controller
{
    public function index(): View
    {
        return view('admin.backups.index');
    }

    /**
     * Erstellt eine Sicherung und bietet sie zum Download an. Die Datei wird
     * mit dem eingegebenen Passwort verschlüsselt; zusätzlich muss der
     * Administrator sein eigenes Kontopasswort bestätigen.
     */
    public function download(Request $request): Response
    {
        $this->confirmAccountPassword($request);

        $request->validate([
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ], [
            'password.required' => 'Bitte ein Passwort für die Sicherungsdatei vergeben.',
            'password.min' => 'Das Passwort für die Sicherungsdatei muss mindestens 10 Zeichen lang sein.',
            'password.confirmed' => 'Die Wiederholung des Sicherungs-Passworts stimmt nicht überein.',
        ]);

        @set_time_limit(0);

        $service = app(BackupService::class);

        try {
            $built = $service->create($request->string('password')->value());
        } catch (BackupException $e) {
            throw ValidationException::withMessages(['password' => $e->getMessage()]);
        }

        $flat = storage_path('framework/backups/dl-'.Str::random(32).'.authbak');
        File::ensureDirectoryExists(dirname($flat));
        File::move($built, $flat);
        File::deleteDirectory(dirname($built));

        AuditLog::record('admin.backup_created', $request->user(), [
            'file' => $service->fileName(),
        ]);

        return response()->download($flat, $service->fileName(), [
            'Content-Type' => 'application/octet-stream',
        ])->deleteFileAfterSend();
    }

    /**
     * Spielt eine hochgeladene Sicherung ein. Danach ist die aktuelle
     * Sitzung ungültig (die Datenbank wurde ersetzt), deshalb wird der
     * Administrator abgemeldet und zur Anmeldung geschickt.
     */
    public function restore(Request $request): RedirectResponse
    {
        UploadLimits::guard($request, 'backup');
        $this->confirmAccountPassword($request);

        $request->validate([
            'backup' => ['required', 'file', 'max:1048576'],
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
        ], [
            'backup.required' => 'Bitte die Sicherungsdatei auswählen.',
            'backup.max' => 'Die Sicherungsdatei ist größer als 1 GB und kann so nicht hochgeladen werden.',
            'password.required' => 'Bitte das Passwort der Sicherungsdatei eingeben.',
            'confirm.accepted' => 'Bitte bestätigen, dass die aktuellen Daten überschrieben werden dürfen.',
        ]);

        @set_time_limit(0);

        try {
            $manifest = app(RestoreService::class)->restore(
                $request->file('backup')->getRealPath(),
                $request->string('password')->value(),
            );
        } catch (BackupException $e) {
            throw ValidationException::withMessages(['backup' => $e->getMessage()]);
        }

        AuditLog::record('admin.backup_restored', $request->user(), [
            'created_at' => $manifest['created_at'] ?? null,
            'app_version' => $manifest['app_version'] ?? null,
        ]);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status',
            'Die Sicherung wurde eingespielt. Bitte melden Sie sich neu an.');
    }

    private function confirmAccountPassword(Request $request): void
    {
        $request->validate([
            'current_password' => ['required', 'string'],
        ], [
            'current_password.required' => 'Bitte zur Bestätigung das eigene Kontopasswort eingeben.',
        ]);

        $user = $request->user();

        if (! $user->password || ! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Das eingegebene Kontopasswort ist nicht korrekt.',
            ]);
        }
    }
}
