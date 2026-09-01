<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingController extends Controller
{
    private const KEYS = [
        'system_name', 'base_url', 'timezone', 'locale', 'session_lifetime',
        'maintenance_mode', 'maintenance_message', 'maintenance_allow',
        'accent_color', 'login_title_mode', 'login_title_text',
    ];

    public function edit(): View
    {
        $settings = collect(self::KEYS)->mapWithKeys(fn ($key) => [$key => SystemSetting::get($key)]);
        $logoPath = SystemSetting::get('logo_path');
        $faviconPath = SystemSetting::get('favicon_path');
        $loginBackgroundPath = SystemSetting::get('login_background_path');

        return view('admin.settings.edit', compact('settings', 'logoPath', 'faviconPath', 'loginBackgroundPath'));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'system_name' => 'required|string|max:255',
            'base_url' => 'required|url',
            'timezone' => 'required|timezone',
            'locale' => 'required|string',
            'session_lifetime' => 'required|integer|min:5',
            'maintenance_message' => 'nullable|string|max:2000',
            'maintenance_allow' => 'nullable|string|max:4000',
            'accent_color' => ['nullable', 'string', 'regex:/^#?[0-9a-fA-F]{6}$/'],
            'login_title_mode' => 'nullable|in:default,hidden,custom',
            'login_title_text' => 'nullable|string|max:255',
        ], [
            'accent_color.regex' => 'Die Akzentfarbe muss ein Hex-Farbwert sein, z. B. #2563EB.',
        ]);

        $data['maintenance_mode'] = $request->boolean('maintenance_mode') ? '1' : '0';
        $data['accent_color'] = \App\Support\AccentPalette::normalize($data['accent_color'] ?? null) ?? '';
        $data['login_title_mode'] = $data['login_title_mode'] ?? 'default';

        foreach ($data as $key => $value) {
            SystemSetting::set($key, (string) ($value ?? ''));
        }

        AuditLog::record('admin.settings_updated', $request->user(), $data);

        return back()->with('status', 'Einstellungen wurden gespeichert.');
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $this->guardAgainstOversizedUpload($request, 'logo');

        $request->validate(['logo' => 'required|image|max:5120'], [
            'logo.required' => 'Bitte eine Bilddatei für das Banner auswählen.',
            'logo.image' => 'Die Datei muss ein Bild sein (PNG, JPG, GIF, SVG oder WebP).',
            'logo.max' => 'Das Banner darf höchstens 5 MB groß sein.',
        ]);

        $this->replaceBrandingFile('logo_path', $request->file('logo'));

        AuditLog::record('admin.settings_updated', $request->user(), ['logo' => 'uploaded']);

        return back()->with('status', 'Banner wurde aktualisiert.');
    }

    public function deleteLogo(Request $request): RedirectResponse
    {
        $this->deleteBrandingFile('logo_path');

        AuditLog::record('admin.settings_updated', $request->user(), ['logo' => 'removed']);

        return back()->with('status', 'Banner wurde entfernt.');
    }

    public function uploadFavicon(Request $request): RedirectResponse
    {
        $this->guardAgainstOversizedUpload($request, 'favicon');

        $request->validate(['favicon' => 'required|image|max:2048'], [
            'favicon.required' => 'Bitte eine Bilddatei für das Favicon auswählen.',
            'favicon.image' => 'Die Datei muss ein Bild sein (PNG, JPG, GIF, SVG oder WebP).',
            'favicon.max' => 'Das Favicon darf höchstens 2 MB groß sein.',
        ]);

        $this->replaceBrandingFile('favicon_path', $request->file('favicon'));

        AuditLog::record('admin.settings_updated', $request->user(), ['favicon' => 'uploaded']);

        return back()->with('status', 'Favicon wurde aktualisiert.');
    }

    public function deleteFavicon(Request $request): RedirectResponse
    {
        $this->deleteBrandingFile('favicon_path');

        AuditLog::record('admin.settings_updated', $request->user(), ['favicon' => 'removed']);

        return back()->with('status', 'Favicon wurde entfernt.');
    }

    public function uploadLoginBackground(Request $request): RedirectResponse
    {
        $this->guardAgainstOversizedUpload($request, 'login_background');

        $request->validate(['login_background' => 'required|image|max:8192'], [
            'login_background.required' => 'Bitte eine Bilddatei für den Login-Hintergrund auswählen.',
            'login_background.image' => 'Die Datei muss ein Bild sein (PNG, JPG, GIF, SVG oder WebP).',
            'login_background.max' => 'Der Login-Hintergrund darf höchstens 8 MB groß sein.',
        ]);

        $this->replaceBrandingFile('login_background_path', $request->file('login_background'));

        AuditLog::record('admin.settings_updated', $request->user(), ['login_background' => 'uploaded']);

        return back()->with('status', 'Login-Hintergrund wurde aktualisiert.');
    }

    public function deleteLoginBackground(Request $request): RedirectResponse
    {
        $this->deleteBrandingFile('login_background_path');

        AuditLog::record('admin.settings_updated', $request->user(), ['login_background' => 'removed']);

        return back()->with('status', 'Login-Hintergrund wurde entfernt.');
    }

    /**
     * PHP verwirft Uploads, die post_max_size/upload_max_filesize überschreiten,
     * bereits bevor Laravel sie sieht - $_FILES ist dann leer oder enthält nur
     * einen Fehlercode. Ohne diesen Check landet man bei der generischen (und
     * bislang englischen) "The :attribute field is required"-Meldung, die für
     * den Nutzer nicht erklärt, dass die Datei schlicht zu groß war.
     */
    private function guardAgainstOversizedUpload(Request $request, string $field): void
    {
        $file = $request->file($field);

        if ($file && ! $file->isValid()) {
            $reason = match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE => 'Die Datei überschreitet das Server-Limit (upload_max_filesize).',
                UPLOAD_ERR_FORM_SIZE => 'Die Datei ist größer als im Formular erlaubt.',
                UPLOAD_ERR_PARTIAL => 'Der Upload wurde unterbrochen und ist unvollständig.',
                UPLOAD_ERR_NO_FILE => 'Es wurde keine Datei übertragen.',
                UPLOAD_ERR_NO_TMP_DIR => 'Auf dem Server fehlt ein temporäres Upload-Verzeichnis (upload_tmp_dir).',
                UPLOAD_ERR_CANT_WRITE => 'Der Server konnte die hochgeladene Datei nicht speichern (Schreibrechte im Temp-Verzeichnis).',
                UPLOAD_ERR_EXTENSION => 'Eine PHP-Erweiterung hat den Upload abgebrochen.',
                default => 'Der Upload ist fehlgeschlagen ('.$file->getErrorMessage().').',
            };

            throw \Illuminate\Validation\ValidationException::withMessages([$field => $reason]);
        }

        if (! $file && $request->server('CONTENT_LENGTH') > $this->postMaxSizeInBytes()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                $field => 'Die Datei ist zu groß für diesen Server. Bitte eine kleinere Bilddatei verwenden.',
            ]);
        }
    }

    private function postMaxSizeInBytes(): int
    {
        return $this->phpIniSizeToBytes(ini_get('post_max_size') ?: '8M');
    }

    private function phpIniSizeToBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtoupper(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => (int) $value,
        };
    }

    private function replaceBrandingFile(string $settingKey, \Illuminate\Http\UploadedFile $file): void
    {
        $existing = SystemSetting::get($settingKey);
        if ($existing) {
            Storage::disk('public')->delete($existing);
        }

        SystemSetting::set($settingKey, $file->store('branding', 'public'));
    }

    private function deleteBrandingFile(string $settingKey): void
    {
        $existing = SystemSetting::get($settingKey);
        if ($existing) {
            Storage::disk('public')->delete($existing);
        }

        SystemSetting::set($settingKey, '');
    }
}
