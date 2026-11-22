<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SystemMail;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Support\SvgSanitizer;
use App\Support\AccentPalette;
use App\Support\CustomCss;
use App\Support\LoginBackgrounds;
use App\Support\Locales;
use App\Support\MailSettings;
use App\Support\Secret;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingController extends Controller
{
    private const KEYS = [
        'system_name', 'base_url', 'timezone', 'locale', 'session_lifetime',
        'maintenance_mode', 'maintenance_message', 'maintenance_allow',
        'accent_color', 'brand_icon_mode', 'brand_icon_shape',
        'login_title_mode', 'login_title_text', 'login_layout',
        'login_background_mode', 'login_background_interval', 'login_background_overlay',
        'custom_css', 'custom_css_scope',
        'logo_height_header', 'logo_height_login',
        'header_title_mode', 'header_title_text',
        'windows_sso_enabled',
        'audit_log_retention_days',
        'audit_forward_enabled', 'audit_forward_host', 'audit_forward_port', 'audit_forward_protocol',
        'password_min_length', 'password_require_mixed_case', 'password_require_number',
        'password_require_symbol', 'password_check_pwned',
        'login_max_attempts', 'login_lockout_minutes',
        'passkey_passwordless_local_enabled', 'passkey_passwordless_ad_enabled',
        'trusted_device_enabled', 'trusted_device_days', 'new_device_email_enabled',
        'mail_enabled', 'mail_host', 'mail_port', 'mail_encryption',
        'mail_username', 'mail_from_address', 'mail_from_name',
    ];

    public function edit(): View
    {
        $settings = collect(self::KEYS)->mapWithKeys(fn ($key) => [$key => SystemSetting::get($key)]);
        $logoPath = SystemSetting::get('logo_path');
        $faviconPath = SystemSetting::get('favicon_path');
        $loginBackgroundPaths = LoginBackgrounds::paths();
        $loginBackgroundPath = $loginBackgroundPaths[0] ?? null;
        $hasMailPassword = filled(SystemSetting::get('mail_password'));
        $mailConfigured = MailSettings::configured();

        return view('admin.settings.edit', compact(
            'settings', 'logoPath', 'faviconPath', 'loginBackgroundPath', 'loginBackgroundPaths', 'hasMailPassword', 'mailConfigured'
        ));
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'system_name' => 'required|string|max:255',
            'base_url' => 'required|url',
            'timezone' => 'required|timezone',
            'locale' => ['required', 'string', Rule::in(array_keys(Locales::available()))],
            'session_lifetime' => 'required|integer|min:5',
            'maintenance_message' => 'nullable|string|max:2000',
            'maintenance_allow' => 'nullable|string|max:4000',
            'accent_color' => ['nullable', 'string', 'regex:/^#?[0-9a-fA-F]{6}$/'],
            'brand_icon_mode' => 'nullable|in:default,initial,hidden',
            'brand_icon_shape' => 'nullable|in:rounded,circle,square',
            'login_title_mode' => 'nullable|in:default,hidden,custom',
            'login_title_text' => 'nullable|string|max:255',
            'login_layout' => 'nullable|in:centered,split',
            'login_background_mode' => ['nullable', Rule::in(LoginBackgrounds::MODES)],
            'login_background_interval' => 'nullable|integer|min:3|max:600',
            'login_background_overlay' => 'nullable|integer|min:0|max:90',
            'custom_css' => ['nullable', 'string', 'max:'.CustomCss::MAX_LENGTH, function (string $attribute, mixed $value, \Closure $fail) {
                if ($problem = CustomCss::problem((string) $value)) {
                    $fail($problem);
                }
            }],
            'custom_css_scope' => ['nullable', Rule::in(CustomCss::SCOPES)],
            'logo_height_header' => 'nullable|integer|min:16|max:96',
            'logo_height_login' => 'nullable|integer|min:24|max:240',
            'header_title_mode' => 'nullable|in:default,hidden,custom',
            'header_title_text' => 'nullable|string|max:255',
            'audit_log_retention_days' => 'nullable|integer|min:0|max:36500',
            'audit_forward_host' => 'nullable|string|max:255',
            'audit_forward_port' => 'nullable|integer|min:1|max:65535',
            'audit_forward_protocol' => 'nullable|in:udp,tcp',
            'password_min_length' => 'nullable|integer|min:6|max:128',
            'login_max_attempts' => 'nullable|integer|min:3|max:100',
            'login_lockout_minutes' => 'nullable|integer|min:1|max:1440',
            'trusted_device_days' => 'nullable|integer|min:1|max:365',
            'mail_host' => 'nullable|string|max:255',
            'mail_port' => 'nullable|integer|min:1|max:65535',
            'mail_encryption' => 'nullable|in:starttls,ssl,none',
            'mail_username' => 'nullable|string|max:255',
            'mail_password' => 'nullable|string|max:255',
            'mail_from_address' => 'nullable|email|max:255',
            'mail_from_name' => 'nullable|string|max:255',
        ], [
            'accent_color.regex' => 'Die Akzentfarbe muss ein Hex-Farbwert sein, z. B. #2563EB.',
            'logo_height_header.*' => 'Die Banner-Höhe im Kopfbereich muss eine Zahl zwischen 16 und 96 sein.',
            'logo_height_login.*' => 'Die Banner-Höhe auf der Anmeldeseite muss eine Zahl zwischen 24 und 240 sein.',
            'login_background_interval.*' => 'Die Wechselzeit muss zwischen 3 und 600 Sekunden liegen.',
            'login_background_overlay.*' => 'Die Abdunklung muss zwischen 0 und 90 Prozent liegen.',
            'custom_css.max' => 'Das eigene CSS darf höchstens '.number_format(CustomCss::MAX_LENGTH, 0, ',', '.').' Zeichen lang sein.',
        ]);

        $data['maintenance_mode'] = $request->boolean('maintenance_mode') ? '1' : '0';
        $data['windows_sso_enabled'] = $request->boolean('windows_sso_enabled') ? '1' : '0';
        $data['audit_forward_enabled'] = $request->boolean('audit_forward_enabled') ? '1' : '0';
        $data['audit_log_retention_days'] = (string) (int) ($data['audit_log_retention_days'] ?? 0);
        $data['audit_forward_port'] = (string) (int) ($data['audit_forward_port'] ?? 514);
        $data['audit_forward_protocol'] = $data['audit_forward_protocol'] ?? 'udp';

        $data['password_min_length'] = (string) (((int) ($data['password_min_length'] ?? 0)) ?: 10);
        $data['login_max_attempts'] = (string) (((int) ($data['login_max_attempts'] ?? 0)) ?: 5);
        $data['login_lockout_minutes'] = (string) (((int) ($data['login_lockout_minutes'] ?? 0)) ?: 1);
        $data['trusted_device_days'] = (string) (((int) ($data['trusted_device_days'] ?? 0)) ?: 120);

        foreach ([
            'password_require_mixed_case', 'password_require_number', 'password_require_symbol', 'password_check_pwned',
            'passkey_passwordless_local_enabled', 'passkey_passwordless_ad_enabled',
            'trusted_device_enabled', 'new_device_email_enabled',
        ] as $flag) {
            $data[$flag] = $request->boolean($flag) ? '1' : '0';
        }
        $data['mail_enabled'] = $request->boolean('mail_enabled') ? '1' : '0';
        $data['mail_encryption'] = $data['mail_encryption'] ?? 'starttls';
        $data['mail_port'] = (string) (((int) ($data['mail_port'] ?? 0)) ?: ($data['mail_encryption'] === 'ssl' ? 465 : 587));

        // Passwort nur überschreiben, wenn ein neuer Wert eingegeben wurde.
        if (filled($request->input('mail_password'))) {
            SystemSetting::set('mail_password', Secret::encrypt($request->string('mail_password')));
        }
        unset($data['mail_password']);

        $data['accent_color'] = AccentPalette::normalize($data['accent_color'] ?? null) ?? '';
        $data['login_title_mode'] = $data['login_title_mode'] ?? 'default';
        $data['login_layout'] = $data['login_layout'] ?? 'centered';
        $data['login_background_mode'] = $data['login_background_mode'] ?? 'fixed';
        $data['login_background_interval'] = (string) (((int) ($data['login_background_interval'] ?? 0)) ?: LoginBackgrounds::DEFAULT_INTERVAL);
        $data['login_background_overlay'] = (string) (int) ($data['login_background_overlay'] ?? LoginBackgrounds::DEFAULT_OVERLAY);
        $data['custom_css'] = trim(str_replace("\r\n", "\n", (string) ($data['custom_css'] ?? '')));
        $data['custom_css_scope'] = $data['custom_css_scope'] ?? 'login';
        $data['header_title_mode'] = $data['header_title_mode'] ?? 'default';
        $data['brand_icon_mode'] = $data['brand_icon_mode'] ?? 'default';
        $data['brand_icon_shape'] = $data['brand_icon_shape'] ?? 'rounded';

        foreach ($data as $key => $value) {
            SystemSetting::set($key, (string) ($value ?? ''));
        }

        // Das CSS selbst nicht ins Protokoll schreiben (kann lang sein), nur ob es sich geändert hat.
        $audit = $data;
        $audit['custom_css'] = $data['custom_css'] === '' ? '' : '('.mb_strlen($data['custom_css']).' Zeichen)';
        AuditLog::record('admin.settings_updated', $request->user(), $audit);

        return back()->with('status', 'Einstellungen wurden gespeichert.');
    }

    public function sendTestMail(Request $request): RedirectResponse
    {
        $data = $request->validate(['test_email' => 'required|email'], [
            'test_email.required' => 'Bitte eine Empfängeradresse für die Testnachricht angeben.',
        ]);

        if (! MailSettings::configured()) {
            return back()->with('error', 'Bitte zuerst den SMTP-Zugang eintragen, „E-Mail-Versand aktiv" setzen und speichern.');
        }

        try {
            $system = SystemSetting::get('system_name') ?: config('app.name');

            // sendNow: der Admin soll sofort sehen, ob der Versand klappt.
            Mail::to($data['test_email'])->sendNow(new SystemMail(
                subjectLine: "Testnachricht von {$system}",
                heading: 'Der E-Mail-Versand funktioniert',
                body: [
                    "Diese Nachricht wurde über die SMTP-Einstellungen von {$system} verschickt.",
                    'Wenn du sie erhalten hast, ist alles richtig eingerichtet.',
                ],
            ));
        } catch (\Throwable $e) {
            return back()->with('error', 'Versand fehlgeschlagen: '.$e->getMessage());
        }

        AuditLog::record('admin.mail_test_sent', $request->user(), ['to' => $data['test_email']]);

        return back()->with('status', 'Testnachricht an '.$data['test_email'].' gesendet.');
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $this->guardAgainstOversizedUpload($request, 'logo');

        $request->validate(['logo' => 'required|image:allow_svg|mimes:png,jpg,jpeg,gif,webp,svg|max:5120'], [
            'logo.required' => 'Bitte eine Bilddatei für das Banner auswählen.',
            'logo.image' => 'Die Datei muss ein Bild sein (PNG, JPG, GIF, WebP oder SVG).',
            'logo.mimes' => 'Erlaubt sind PNG, JPG, GIF, WebP oder SVG.',
            'logo.max' => 'Das Banner darf höchstens 5 MB groß sein.',
        ]);

        $this->replaceBrandingFile('logo_path', $request->file('logo'), 'logo');

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

        $request->validate(['favicon' => 'required|file|mimes:png,jpg,jpeg,gif,webp,ico,bmp,svg|max:2048'], [
            'favicon.required' => 'Bitte eine Bilddatei für das Favicon auswählen.',
            'favicon.mimes' => 'Erlaubt sind PNG, JPG, GIF, WebP, BMP, ICO oder SVG.',
            'favicon.max' => 'Das Favicon darf höchstens 2 MB groß sein.',
        ]);

        $this->replaceBrandingFile('favicon_path', $request->file('favicon'), 'favicon');

        AuditLog::record('admin.settings_updated', $request->user(), ['favicon' => 'uploaded']);

        return back()->with('status', 'Favicon wurde aktualisiert.');
    }

    public function deleteFavicon(Request $request): RedirectResponse
    {
        $this->deleteBrandingFile('favicon_path');

        AuditLog::record('admin.settings_updated', $request->user(), ['favicon' => 'removed']);

        return back()->with('status', 'Favicon wurde entfernt.');
    }

    /** Lädt ein oder mehrere Hintergrundbilder hoch und hängt sie an die Liste an. */
    public function uploadLoginBackground(Request $request): RedirectResponse
    {
        $this->guardAgainstOversizedUpload($request, 'login_background');

        $files = array_values(array_filter(Arr::wrap($request->file('login_background'))));

        if ($files === []) {
            throw ValidationException::withMessages(['login_background' => 'Bitte eine Bilddatei für den Login-Hintergrund auswählen.']);
        }

        $free = LoginBackgrounds::MAX_IMAGES - count(LoginBackgrounds::paths());
        if (count($files) > $free) {
            throw ValidationException::withMessages(['login_background' => $free > 0
                ? "Es sind höchstens ".LoginBackgrounds::MAX_IMAGES." Hintergrundbilder möglich. Es können noch {$free} hinzugefügt werden."
                : 'Es sind bereits '.LoginBackgrounds::MAX_IMAGES.' Hintergrundbilder hinterlegt. Bitte zuerst eines entfernen.']);
        }

        foreach ($files as $file) {
            Validator::make(['login_background' => $file], [
                'login_background' => 'required|image:allow_svg|mimes:png,jpg,jpeg,gif,webp,svg|max:8192',
            ], [
                'login_background.image' => 'Die Datei „'.$file->getClientOriginalName().'" muss ein Bild sein (PNG, JPG, GIF, WebP oder SVG).',
                'login_background.mimes' => 'Die Datei „'.$file->getClientOriginalName().'" hat kein erlaubtes Format (PNG, JPG, GIF, WebP oder SVG).',
                'login_background.max' => 'Die Datei „'.$file->getClientOriginalName().'" ist größer als 8 MB.',
            ])->validate();
        }

        foreach ($files as $file) {
            LoginBackgrounds::add(SvgSanitizer::storeImage($file, 'branding', 'login_background'));
        }

        AuditLog::record('admin.settings_updated', $request->user(), ['login_background' => 'uploaded', 'count' => count($files)]);

        return back()->with('status', count($files) === 1
            ? 'Hintergrundbild wurde hinzugefügt.'
            : count($files).' Hintergrundbilder wurden hinzugefügt.');
    }

    /** Entfernt alle Hintergrundbilder. */
    public function deleteLoginBackground(Request $request): RedirectResponse
    {
        LoginBackgrounds::removeAll();

        AuditLog::record('admin.settings_updated', $request->user(), ['login_background' => 'removed']);

        return back()->with('status', 'Alle Hintergrundbilder wurden entfernt.');
    }

    public function destroyLoginBackgroundImage(Request $request, string $file): RedirectResponse
    {
        abort_unless(LoginBackgrounds::remove($file), 404);

        AuditLog::record('admin.settings_updated', $request->user(), ['login_background' => 'image_removed']);

        return back()->with('status', 'Hintergrundbild wurde entfernt.');
    }

    public function promoteLoginBackgroundImage(Request $request, string $file): RedirectResponse
    {
        abort_unless(LoginBackgrounds::promote($file), 404);

        AuditLog::record('admin.settings_updated', $request->user(), ['login_background' => 'image_promoted']);

        return back()->with('status', 'Das Bild steht jetzt an erster Stelle.');
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
        $uploaded = $request->file($field);

        foreach (array_filter(Arr::wrap($uploaded)) as $file) {
            $this->guardUploadedFile($file, $field);
        }

        if (! $uploaded && $request->server('CONTENT_LENGTH') > $this->postMaxSizeInBytes()) {
            throw ValidationException::withMessages([
                $field => 'Die Datei ist zu groß für diesen Server. Bitte eine kleinere Bilddatei verwenden.',
            ]);
        }
    }

    private function guardUploadedFile(UploadedFile $file, string $field): void
    {
        if (! $file->isValid()) {
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

    private function replaceBrandingFile(string $settingKey, UploadedFile $file, string $field): void
    {
        $path = SvgSanitizer::storeImage($file, 'branding', $field);

        $existing = SystemSetting::get($settingKey);
        if ($existing) {
            Storage::disk('public')->delete($existing);
        }

        SystemSetting::set($settingKey, $path);
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
