<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\Backup\AutoBackupRunner;
use App\Services\Backup\BackupDestination;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use App\Services\Backup\RestoreService;
use App\Support\Secret;
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
    /** Einstellungs-Schlüssel der automatischen Sicherung (Präfix auto_backup_). */
    private const AUTO_KEYS = [
        'enabled', 'frequency', 'time', 'keep', 'target', 'dir',
        'host', 'port', 'username',
        'ftp_ssl', 's3_key', 's3_region', 's3_bucket', 's3_endpoint', 's3_path_style',
        'last_run', 'last_error', 'last_file',
    ];

    public function index(AutoBackupRunner $runner): View
    {
        $auto = collect(self::AUTO_KEYS)->mapWithKeys(
            fn ($k) => ['auto_backup_'.$k => SystemSetting::get('auto_backup_'.$k)]
        );

        return view('admin.backups.index', [
            'auto' => $auto,
            'hasRemotePassword' => filled(SystemSetting::get('auto_backup_remote_password')),
            'hasS3Secret' => filled(SystemSetting::get('auto_backup_s3_secret')),
            'hasArchivePassword' => filled(SystemSetting::get('auto_backup_archive_password')),
        ]);
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

    /**
     * Speichert die Einstellungen der automatischen Sicherung.
     */
    public function updateAuto(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'frequency' => 'required|in:daily,weekly',
            'time' => 'required|date_format:H:i',
            'keep' => 'required|integer|min:0|max:365',
            'target' => 'required|in:'.implode(',', BackupDestination::TARGETS),
            'dir' => 'nullable|string|max:255',
            'archive_password' => 'nullable|string|min:10',
            'host' => 'nullable|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'nullable|string|max:255',
            'remote_password' => 'nullable|string|max:255',
            's3_key' => 'nullable|string|max:255',
            's3_secret' => 'nullable|string|max:255',
            's3_region' => 'nullable|string|max:64',
            's3_bucket' => 'nullable|string|max:255',
            's3_endpoint' => 'nullable|string|max:255',
        ]);

        $set = fn (string $key, $value) => SystemSetting::set('auto_backup_'.$key, (string) $value);

        $set('enabled', $request->boolean('enabled') ? '1' : '0');
        $set('frequency', $data['frequency']);
        $set('time', $data['time']);
        $set('keep', (int) $data['keep']);
        $set('target', $data['target']);
        $set('dir', trim($data['dir'] ?? ''));
        $set('ftp_ssl', $request->boolean('ftp_ssl') ? '1' : '0');
        $set('s3_path_style', $request->boolean('s3_path_style') ? '1' : '0');

        foreach (['host', 'port', 'username', 's3_key', 's3_region', 's3_bucket', 's3_endpoint'] as $plain) {
            $set($plain, $data[$plain] ?? '');
        }

        // Geheimnisse: nur überschreiben, wenn ein neuer Wert eingegeben wurde.
        foreach (['archive_password' => 'archive_password', 'remote_password' => 'remote_password', 's3_secret' => 's3_secret'] as $field => $key) {
            if (filled($data[$field] ?? null)) {
                $set($key, Secret::encrypt($data[$field]));
            }
        }

        AuditLog::record('admin.auto_backup_configured', $request->user(), [
            'enabled' => $request->boolean('enabled'),
            'target' => $data['target'],
            'frequency' => $data['frequency'],
        ]);

        return back()->with('status', 'Einstellungen der automatischen Sicherung wurden gespeichert.');
    }

    /**
     * Startet sofort eine automatische Sicherung (auch wenn sie deaktiviert ist).
     */
    public function runAuto(Request $request, AutoBackupRunner $runner): RedirectResponse
    {
        @set_time_limit(0);

        $result = $runner->run();

        return $result['ok']
            ? back()->with('status', 'Sicherung erstellt und hochgeladen: '.$result['file'])
            : back()->with('error', 'Sicherung fehlgeschlagen: '.$result['message']);
    }

    /**
     * Prüft, ob das konfigurierte Ziel erreichbar und beschreibbar ist.
     */
    public function testDestination(Request $request, BackupDestination $destination): RedirectResponse
    {
        try {
            $disk = $destination->disk();
            $probe = $destination->path('.verbindungstest-'.Str::random(8));
            $disk->put($probe, 'ok');
            $disk->delete($probe);
        } catch (\Throwable $e) {
            return back()->with('error', 'Verbindung zum Ziel fehlgeschlagen: '.$e->getMessage());
        }

        return back()->with('status', 'Verbindung zum Ziel erfolgreich – schreiben und löschen funktioniert.');
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
