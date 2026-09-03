<?php

namespace App\Providers;

use App\Models\SystemSetting;
use App\Support\AccentPalette;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The installer lets an admin rename the system at any time under
        // Administration -> Systemeinstellungen (system_settings.system_name).
        // That is the single source of truth for the displayed name - NOT
        // config('app.name')/.env APP_NAME, which is only written once
        // during installation and never kept in sync afterwards.
        View::share('systemName', $this->resolveSystemName());
        View::share('systemLogoUrl', $this->resolveBrandingUrl('logo_path'));
        View::share('systemFaviconUrl', $this->resolveBrandingUrl('favicon_path'));
        View::share('loginBackgroundUrl', $this->resolveBrandingUrl('login_background_path'));
        View::share('loginTitle', $this->resolveLoginTitle());
        View::share('brandIcon', [
            'mode' => $this->resolveSetting('brand_icon_mode') ?: 'default',
            'shape' => $this->resolveSetting('brand_icon_shape') ?: 'rounded',
        ]);
        View::share('accentPalette', AccentPalette::from($this->resolveSetting('accent_color')));

        // Same story for the timezone: Administration -> Systemeinstellungen
        // writes system_settings.timezone, but Laravel's own default timezone
        // is only applied once at boot (Illuminate\Foundation\Bootstrap\
        // LoadConfiguration calls date_default_timezone_set() from
        // config('app.timezone') BEFORE any service provider runs). Simply
        // changing config('app.timezone') here would therefore have no
        // effect on date()/Carbon output - we must call
        // date_default_timezone_set() again ourselves with the DB value.
        $timezone = $this->resolveTimezone();
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);

        // Sprache aus den Systemeinstellungen (Administration -> Systemeinstellungen),
        // sonst der Wert aus der Konfiguration.
        $locale = $this->resolveSetting('locale');
        if ($locale) {
            app()->setLocale($locale);
        }
    }

    private function resolveSystemName(): string
    {
        try {
            if (! Schema::hasTable('system_settings')) {
                return config('app.name');
            }

            return SystemSetting::get('system_name') ?: config('app.name');
        } catch (\Throwable) {
            return config('app.name');
        }
    }

    /**
     * Titel unter dem Logo auf der Anmeldeseite:
     * null  => ausblenden
     * string => anzuzeigender Text (Systemname oder eigener Text)
     */
    private function resolveLoginTitle(): ?string
    {
        $mode = $this->resolveSetting('login_title_mode') ?: 'default';

        if ($mode === 'hidden') {
            return null;
        }

        if ($mode === 'custom') {
            $text = trim((string) $this->resolveSetting('login_title_text'));

            return $text !== '' ? $text : null;
        }

        return $this->resolveSystemName();
    }

    private function resolveSetting(string $key): ?string
    {
        try {
            if (! Schema::hasTable('system_settings')) {
                return null;
            }

            return SystemSetting::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveTimezone(): string
    {
        try {
            if (! Schema::hasTable('system_settings')) {
                return config('app.timezone');
            }

            $timezone = SystemSetting::get('timezone');

            // A garbage value (e.g. hand-edited in the DB) must never crash
            // every single request via date_default_timezone_set().
            if ($timezone && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                return $timezone;
            }

            return config('app.timezone');
        } catch (\Throwable) {
            return config('app.timezone');
        }
    }

    private function resolveBrandingUrl(string $key): ?string
    {
        try {
            if (! Schema::hasTable('system_settings')) {
                return null;
            }

            $path = SystemSetting::get($key);

            return $path ? Storage::disk('public')->url($path) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
