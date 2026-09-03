<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SecuritySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        SystemSetting::set('installed', '1');

        return User::factory()->create([
            'auth_source' => 'local', 'is_admin' => true, 'is_active' => true,
            'password' => Hash::make('Password123!'),
        ]);
    }

    private function newUserPayload(string $password): array
    {
        return [
            'username' => 'neu'.random_int(100, 999),
            'first_name' => 'Neu', 'last_name' => 'Benutzer',
            'email' => 'neu'.random_int(1000, 9999).'@example.test',
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }

    public function test_minimum_length_is_enforced(): void
    {
        SystemSetting::set('password_min_length', '14');
        $admin = $this->admin();

        $this->actingAs($admin)->from(route('admin.users.create'))
            ->post(route('admin.users.store'), $this->newUserPayload('kurz-1234567'))
            ->assertSessionHasErrors(['password' => 'Das Passwort muss mindestens 14 Zeichen lang sein.']);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->newUserPayload('lang-genug-1234'))
            ->assertSessionHasNoErrors();
    }

    public function test_complexity_requirements_are_applied(): void
    {
        SystemSetting::set('password_min_length', '8');
        SystemSetting::set('password_require_number', '1');
        SystemSetting::set('password_require_symbol', '1');
        $admin = $this->admin();

        $this->actingAs($admin)->from(route('admin.users.create'))
            ->post(route('admin.users.store'), $this->newUserPayload('nurbuchstaben'))
            ->assertSessionHasErrors('password');

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->newUserPayload('Passt-2026!'))
            ->assertSessionHasNoErrors();
    }

    public function test_pwned_password_is_rejected_when_the_check_is_on(): void
    {
        SystemSetting::set('password_min_length', '8');
        SystemSetting::set('password_check_pwned', '1');

        $suffix = substr(strtoupper(sha1('password123')), 5);
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response($suffix.":4242\r\nZZZZZ:1"),
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)->from(route('admin.users.create'))
            ->post(route('admin.users.store'), $this->newUserPayload('password123'))
            ->assertSessionHasErrors('password');
    }

    public function test_clean_password_passes_the_pwned_check(): void
    {
        SystemSetting::set('password_min_length', '8');
        SystemSetting::set('password_check_pwned', '1');

        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('0000000000000000000000000000000000000:1'),
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), $this->newUserPayload('nicht-geleakt-2026'))
            ->assertSessionHasNoErrors();
    }

    public function test_login_lockout_honours_the_configured_attempt_limit(): void
    {
        SystemSetting::set('installed', '1');
        SystemSetting::set('login_max_attempts', '3');

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('login.attempt'), ['username' => 'niemand', 'password' => 'falsch'])
                ->assertSessionHasErrors('username');
        }

        $this->post(route('login.attempt'), ['username' => 'niemand', 'password' => 'falsch'])
            ->assertSessionHasErrors(['username' => 'Zu viele Anmeldeversuche. Bitte in 60 Sekunden erneut versuchen.']);
    }

    public function test_hint_reflects_the_policy(): void
    {
        SystemSetting::set('password_min_length', '12');
        SystemSetting::set('password_require_number', '1');

        $hint = SecuritySettings::passwordHint();

        $this->assertStringContainsString('12 Zeichen', $hint);
        $this->assertStringContainsString('Ziffer', $hint);
    }
}
