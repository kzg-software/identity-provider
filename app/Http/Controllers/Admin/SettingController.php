<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Support\AccentPalette;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingController extends Controller
{
    private const KEYS = [
        'system_name', 'base_url', 'timezone', 'locale', 'session_lifetime',
        'maintenance_mode', 'maintenance_message', 'maintenance_allow',
        'accent_color', 'brand_icon_mode', 'brand_icon_shape',
        'login_title_mode', 'login_title_text',
        'windows_sso_enabled',
        'audit_log_retention_days',
        'audit_forward_enabled', 'audit_forward_host', 'audit_forward_port', 'audit_forward_protocol',
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
            'brand_icon_mode' => 'nullable|in:default,initial,hidden',
            'brand_icon_shape' => 'nullable|in:rounded,circle,square',
            'login_title_mode' => 'nullable|in:default,hidden,custom',
            'login_title_text' => 'nullable|string|max:255',
            'audit_log_retention_days' => 'nullable|integer|min:0|max:36500',
            'audit_forward_host' => 'nullable|string|max:255',
            'audit_forward_port' => 'nullable|integer|min:1|max:65535',
            'audit_forward_protocol' => 'nullable|in:udp,tcp',
        ], [
            'accent_color.regex' => 'Die Akzentfarbe muss ein Hex-Farbwert sein, z. B. #2563EB.',
        ]);

        $data['maintenance_mode'] = $request->boolean('maintenance_mode') ? '1' : '0';
        $data['windows_sso_enabled'] = $request->boolean('windows_sso_enabled') ? '1' : '0';
        $data['audit_forward_enabled'] = $request->boolean('audit_forward_enabled') ? '1' : '0';
        $data['audit_log_retention_days'] = (string) (int) ($data['audit_log_retention_days'] ?? 0);
        $data['audit_forward_port'] = (string) (int) ($data['audit_forward_port'] ?? 514);
        $data['audit_forward_protocol'] = $data['audit_forward_protocol'] ?? 'udp';
        $data['accent_color'] = AccentPalette::normalize($data['accent_color'] ?? null) ?? '';
        $data['login_title_mode'] = $data['login_title_mode'] ?? 'default';
        $data['brand_icon_mode'] = $data['brand_icon_mode'] ?? 'default';
        $data['brand_icon_shape'] = $data['brand_icon_shape'] ?? 'rounded';

        foreach ($data as $key => $value) {
            SystemSetting::set($key, (string) ($value ?? ''));
        }

        AuditLog::record('admin.settings_updated', $request->user(), $data);

        return back()->with('status', 'Einstellungen wurden gespeichert.');
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $this->guardAgainstOversizedUpload($request, 'logo');

        // Bewusst kein SVG: eine SVG-Datei kann Skript enthalten und wird beim
        // direkten Aufruf im Browser ausgeführt (Stored XSS auf der eigenen Domain).
        $request->validate(['logo' => 'required|image|mimes:png,jpg,jpeg,gif,webp|max:5120'], [
            'logo.required' => 'Bitte eine Bilddatei für das Banner auswählen.',
            'logo.image' => 'Die Datei muss ein Bild sein (PNG, JPG, GIF oder WebP).',
            'logo.mimes' => 'Erlaubt sind PNG, JPG, GIF oder WebP.',
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

        $request->validate(['favicon' => 'required|file|mimes:png,jpg,jpeg,gif,webp,ico,bmp|max:2048'], [
            'favicon.required' => 'Bitte eine Bilddatei für das Favicon auswählen.',
            'favicon.mimes' => 'Erlaubt sind PNG, JPG, GIF, WebP, BMP oder ICO.',
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

        $request->validate(['login_background' => 'required|image|mimes:png,jpg,jpeg,gif,webp|max:8192'], [
            'login_background.required' => 'Bitte eine Bilddatei für den Login-Hintergrund auswählen.',
            'login_background.image' => 'Die Datei muss ein Bild sein (PNG, JPG, GIF oder WebP).',
            'login_background.mimes' => 'Erlaubt sind PNG, JPG, GIF oder WebP.',
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

            throw ValidationException::withMessages([$field => $reason]);
        }

        if (! $file && $request->server('CONTENT_LENGTH') > $this->postMaxSizeInBytes()) {
            throw ValidationException::withMessages([
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

    private function replaceBrandingFile(string $settingKey, UploadedFile $file): void
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
