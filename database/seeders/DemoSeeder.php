<?php

namespace Database\Seeders;

use App\Models\AccessPolicy;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Directory;
use App\Models\DirectoryGroup;
use App\Models\DirectoryUser;
use App\Models\GroupRoleMapping;
use App\Models\SystemSetting;
use App\Models\User;
use App\Oidc\OidcKeyService;
use App\Saml\SamlCertificateService;
use App\Services\ProviderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo-Instanz mit Beispieldaten (Screenshots, Ausprobieren).
 *
 *   php artisan migrate --seed --seeder=DemoSeeder
 *
 * Anmeldung: demo / demo (Administrator), anna.schmidt / demo (Portal).
 * Nur für eine leere Datenbank gedacht.
 */
class DemoSeeder extends Seeder
{
    private const PASSWORD = 'demo';

    public function run(ProviderService $providers): void
    {
        if (User::exists()) {
            throw new RuntimeException('DemoSeeder nur auf einer leeren Datenbank ausführen.');
        }

        $this->settings();

        app(OidcKeyService::class)->rotate();
        app(SamlCertificateService::class)->rotate();

        $ad = $this->directories();
        $users = $this->users($ad);
        $apps = $this->applications($providers);

        // Die beim Anlegen der Provider protokollierten Einträge stören in der Demo.
        AuditLog::query()->delete();
        $this->auditLog($users, $apps);
    }

    private function settings(): void
    {
        foreach ([
            'installed' => '1',
            'installed_at' => now()->subMonths(4)->toIso8601String(),
            'system_name' => 'Musterfirma Login',
            'base_url' => config('app.url'),
            'timezone' => 'Europe/Berlin',
            'locale' => 'de',
            'session_lifetime' => '480',
        ] as $key => $value) {
            SystemSetting::set($key, $value);
        }

        config(['app.timezone' => 'Europe/Berlin']);
        date_default_timezone_set('Europe/Berlin');
    }

    private function directories(): Directory
    {
        $ad = Directory::create([
            'name' => 'Musterfirma AD',
            'type' => 'active_directory',
            'domain' => 'musterfirma.local',
            'netbios_domain' => 'MUSTERFIRMA',
            'ldap_server' => 'dc01.musterfirma.local',
            'ldap_port' => 636,
            'use_ldaps' => true,
            'base_dn' => 'DC=musterfirma,DC=local',
            'bind_user' => 'svc-login@musterfirma.local',
            'is_active' => true,
            'delta_sync_enabled' => true,
        ]);
        $ad->forceFill([
            'last_sync_at' => now()->subMinutes(7),
            'last_full_sync_at' => now()->subHours(9),
            'last_sync_duration_seconds' => 4,
            'last_sync_user_count' => 214,
            'last_sync_group_count' => 37,
        ])->save();

        Directory::create([
            'name' => 'Testdomäne',
            'type' => 'active_directory',
            'domain' => 'test.musterfirma.local',
            'ldap_server' => 'dc-test.musterfirma.local',
            'ldap_port' => 636,
            'use_ldaps' => true,
            'base_dn' => 'DC=test,DC=musterfirma,DC=local',
            'is_active' => false,
        ]);

        return $ad;
    }

    /** @return array<string, User> */
    private function users(Directory $ad): array
    {
        $groups = [];
        foreach ([
            'GG-IT-Admins' => 'IT-Administration',
            'GG-Entwicklung' => 'Softwareentwicklung',
            'GG-Vertrieb' => 'Vertrieb Innen- und Außendienst',
            'GG-Personal' => 'Personalabteilung',
            'GG-Alle-Mitarbeiter' => 'Alle festangestellten Mitarbeiter',
        ] as $name => $description) {
            $groups[$name] = DirectoryGroup::create([
                'directory_id' => $ad->id,
                'object_guid' => (string) Str::uuid(),
                'name' => $name,
                'distinguished_name' => "CN={$name},OU=Gruppen,DC=musterfirma,DC=local",
                'description' => $description,
            ]);
        }

        GroupRoleMapping::create(['directory_group_id' => $groups['GG-IT-Admins']->id, 'group_name' => 'GG-IT-Admins', 'directory_id' => $ad->id, 'role' => 'admin']);
        GroupRoleMapping::create(['directory_group_id' => $groups['GG-Entwicklung']->id, 'group_name' => 'GG-Entwicklung', 'directory_id' => $ad->id, 'role' => 'developer']);

        $users['demo'] = User::create([
            'username' => 'demo', 'name' => 'Demo Admin', 'first_name' => 'Demo', 'last_name' => 'Admin',
            'display_name' => 'Demo Admin', 'email' => 'demo@musterfirma.de',
            'password' => Hash::make(self::PASSWORD), 'auth_source' => 'local', 'is_active' => true,
            'manual_roles' => ['admin'], 'department' => 'IT',
        ]);

        $people = [
            ['anna.schmidt', 'Anna', 'Schmidt', 'Vertrieb', 'Vertriebsleitung', ['GG-Vertrieb']],
            ['lukas.becker', 'Lukas', 'Becker', 'IT', 'Systemadministrator', ['GG-IT-Admins']],
            ['sophie.wagner', 'Sophie', 'Wagner', 'Entwicklung', 'Softwareentwicklerin', ['GG-Entwicklung']],
            ['jonas.hoffmann', 'Jonas', 'Hoffmann', 'Entwicklung', 'Teamleiter Entwicklung', ['GG-Entwicklung']],
            ['marie.koch', 'Marie', 'Koch', 'Personal', 'Personalreferentin', ['GG-Personal']],
            ['felix.richter', 'Felix', 'Richter', 'Vertrieb', 'Außendienst', ['GG-Vertrieb']],
            ['lena.klein', 'Lena', 'Klein', 'Buchhaltung', 'Buchhalterin', []],
            ['paul.wolf', 'Paul', 'Wolf', 'Entwicklung', 'Werkstudent', ['GG-Entwicklung']],
            ['emma.neumann', 'Emma', 'Neumann', 'Marketing', 'Marketing-Managerin', []],
            ['tim.schwarz', 'Tim', 'Schwarz', 'IT', 'IT-Support', []],
        ];

        foreach ($people as $i => [$sam, $first, $last, $department, $position, $memberOf]) {
            $email = "{$sam}@musterfirma.de";
            $user = User::create([
                'username' => $sam, 'name' => "{$first} {$last}", 'first_name' => $first, 'last_name' => $last,
                'display_name' => "{$first} {$last}", 'email' => $email,
                'password' => Hash::make(self::PASSWORD), 'auth_source' => 'active_directory',
                'directory_id' => $ad->id, 'sam_account_name' => $sam, 'upn' => "{$sam}@musterfirma.local",
                'domain' => 'MUSTERFIRMA', 'department' => $department, 'position' => $position,
                'company' => 'Musterfirma GmbH', 'is_active' => $sam !== 'paul.wolf',
                'roles' => in_array('GG-IT-Admins', $memberOf, true) ? ['admin'] : [],
                'last_synced_at' => now()->subMinutes(7),
                'last_login_at' => now()->subHours($i * 5 + 1),
                'last_login_method' => $i % 3 === 0 ? 'windows_sso' : 'password',
            ]);

            $directoryUser = DirectoryUser::create([
                'directory_id' => $ad->id, 'user_id' => $user->id, 'object_guid' => (string) Str::uuid(),
                'sam_account_name' => $sam, 'upn' => "{$sam}@musterfirma.local",
                'first_name' => $first, 'last_name' => $last, 'display_name' => "{$first} {$last}",
                'email' => $email, 'department' => $department, 'position' => $position,
                'company' => 'Musterfirma GmbH', 'domain' => 'MUSTERFIRMA',
                'distinguished_name' => "CN={$first} {$last},OU=Benutzer,DC=musterfirma,DC=local",
                'account_status' => 'active',
            ]);

            foreach ([...$memberOf, 'GG-Alle-Mitarbeiter'] as $group) {
                $directoryUser->groups()->attach($groups[$group]->id, ['is_nested' => false, 'synced_at' => now()]);
            }

            $users[$sam] = $user;
        }

        return $users;
    }

    /** @return array<string, Application> */
    private function applications(ProviderService $providers): array
    {
        $oidc = fn (string $name, string $host) => $providers->createOidc([
            'name' => $name,
            'redirect_uris' => "https://{$host}/oauth/callback",
            'scopes' => ['openid', 'profile', 'email', 'groups'],
        ])->id;

        $saml = fn (string $name, string $host) => $providers->createSaml([
            'name' => $name,
            'entity_id' => "https://{$host}/saml/metadata",
            'acs_url' => "https://{$host}/saml/acs",
            'slo_url' => "https://{$host}/saml/slo",
            'name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        ])->id;

        $defs = [
            ['Git', 'git', 'Quellcode, Merge Requests und CI', 'Entwicklung', $oidc('Git', 'git.musterfirma.de')],
            ['Monitoring', 'monitoring', 'Dashboards und Alarme der Server', 'Entwicklung', $oidc('Monitoring', 'monitoring.musterfirma.de')],
            ['Dateiablage', 'dateiablage', 'Dateien teilen und gemeinsam bearbeiten', 'Zusammenarbeit', $oidc('Dateiablage', 'cloud.musterfirma.de')],
            ['Wiki', 'wiki', 'Handbücher, Prozesse und Protokolle', 'Zusammenarbeit', $oidc('Wiki', 'wiki.musterfirma.de')],
            ['Helpdesk', 'helpdesk', 'Tickets an die IT', 'Zusammenarbeit', $saml('Helpdesk', 'helpdesk.musterfirma.de')],
            ['Personalportal', 'personalportal', 'Urlaub, Gehaltsabrechnung, Stammdaten', 'Verwaltung', $saml('Personalportal', 'hr.musterfirma.de')],
            ['Zeiterfassung', 'zeiterfassung', 'Arbeitszeiten buchen', 'Verwaltung', null],
        ];

        $apps = [];
        foreach ($defs as [$name, $slug, $description, $category, $providerId]) {
            $apps[$slug] = Application::create([
                'provider_id' => $providerId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'logo_path' => $this->logo($slug),
                'category' => $category,
                'launch_url' => "https://{$slug}.musterfirma.de",
                'visibility' => Application::VISIBILITY_PORTAL,
                'login_mode' => 'user_choice',
                'consent_required' => false,
                'consent_mode' => 'skip',
                'is_active' => true,
            ]);
        }

        AccessPolicy::create(['application_id' => $apps['personalportal']->id, 'effect' => 'allow', 'subject_type' => 'group', 'subject_value' => 'GG-Alle-Mitarbeiter', 'priority' => 10]);
        AccessPolicy::create(['application_id' => $apps['monitoring']->id, 'effect' => 'allow', 'subject_type' => 'group', 'subject_value' => 'GG-IT-Admins', 'priority' => 10]);
        AccessPolicy::create(['application_id' => $apps['monitoring']->id, 'effect' => 'allow', 'subject_type' => 'group', 'subject_value' => 'GG-Entwicklung', 'priority' => 5]);

        $apps['zeiterfassung']->update(['access_time_windows' => "Mo-Fr 06:00-20:00"]);

        return $apps;
    }

    private function logo(string $slug): string
    {
        [$color, $icon] = [
            'git' => ['#f05133', '<circle cx="6" cy="6" r="2.5"/><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="8" r="2.5"/><path d="M6 8.5v7M18 10.5c0 4-6 3-11 6"/>'],
            'monitoring' => ['#7c3aed', '<path d="M3 3v18h18"/><path d="m7 15 4-5 3 3 5-7"/>'],
            'dateiablage' => ['#0284c7', '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'],
            'wiki' => ['#059669', '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 21V5M9 8h6M9 12h6"/>'],
            'helpdesk' => ['#d97706', '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="m5.6 5.6 3.9 3.9M14.5 14.5l3.9 3.9M18.4 5.6l-3.9 3.9M9.5 14.5l-3.9 3.9"/>'],
            'personalportal' => ['#db2777', '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>'],
            'zeiterfassung' => ['#475569', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'],
        ][$slug];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">'
            ."<rect width=\"48\" height=\"48\" rx=\"11\" fill=\"{$color}\"/>"
            .'<g transform="translate(12 12)" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            .$icon.'</g></svg>';

        $path = "applications/demo-{$slug}.svg";
        Storage::disk('public')->put($path, $svg);

        return $path;
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Application>  $apps
     */
    private function auditLog(array $users, array $apps): void
    {
        $entries = [
            [5, 'demo', 'login.success', null, ['method' => 'password']],
            [11, 'anna.schmidt', 'login.windows_sso', null, []],
            [12, 'anna.schmidt', 'oauth.authorize.success', 'dateiablage', []],
            [26, 'lukas.becker', 'webauthn.login', null, ['passwordless' => true]],
            [27, 'lukas.becker', 'oauth.authorize.success', 'monitoring', []],
            [41, 'sophie.wagner', 'login.success', null, ['method' => 'password']],
            [42, 'sophie.wagner', 'oauth.authorize.success', 'git', []],
            [58, 'felix.richter', 'login.failed', null, ['method' => 'password', 'reason' => 'invalid_credentials']],
            [59, 'felix.richter', 'login.success', null, ['method' => 'password']],
            [60, 'felix.richter', 'saml.sso.success', 'helpdesk', []],
            [95, 'marie.koch', 'login.success', null, ['method' => 'password']],
            [96, 'marie.koch', 'saml.sso.success', 'personalportal', []],
            [130, 'demo', 'oauth.application_updated', 'zeiterfassung', []],
            [180, 'jonas.hoffmann', 'webauthn.registered', null, []],
            [240, 'demo', 'oidc.key_rotated', null, []],
        ];

        foreach ($entries as [$minutesAgo, $user, $event, $app, $meta]) {
            AuditLog::create([
                'user_id' => $users[$user]->id,
                'event' => $event,
                'ip_address' => '10.20.30.'.(10 + strlen($user)),
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'application_id' => $app ? $apps[$app]->id : null,
                'metadata' => $meta,
            ])->forceFill(['created_at' => Carbon::now()->subMinutes($minutesAgo)])->save();
        }
    }
}
