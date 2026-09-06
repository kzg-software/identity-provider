<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SecuritySettings;
use App\Webauthn\WebAuthnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakeWebAuthnService;
use Tests\TestCase;

class WebauthnPasskeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(WebAuthnService::class, new FakeWebAuthnService);
        SystemSetting::set('installed', '1');
    }

    private function localUser(): User
    {
        return User::factory()->create([
            'auth_source' => 'local', 'is_active' => true, 'password' => Hash::make('Password123!'),
        ]);
    }

    // ----- Registrierung (Self-Service) --------------------------------

    public function test_security_page_and_totp_setup_render(): void
    {
        $user = $this->localUser();

        $this->actingAs($user)->get(route('profile.security'))->assertOk()->assertSee('Passkeys');
        $this->actingAs($user)->get(route('profile.security.totp.setup'))->assertOk()->assertSee('Authenticator-App einrichten');
    }

    public function test_totp_can_be_set_up_and_used_as_second_factor(): void
    {
        $user = $this->localUser();
        $google2fa = new Google2FA;

        $this->actingAs($user)->get(route('profile.security.totp.setup'))->assertOk();
        $secret = session('2fa.totp_setup_secret');
        $this->assertNotEmpty($secret);

        $this->actingAs($user)
            ->post(route('profile.security.totp.store'), ['code' => $google2fa->getCurrentOtp($secret)])
            ->assertRedirect(route('profile.security'));

        $this->assertTrue($user->fresh()->twoFactor->hasTotp());
        $this->assertNotEmpty($user->fresh()->twoFactor->recoveryCodes());
    }

    public function test_passkey_options_requires_authentication(): void
    {
        $this->post(route('profile.security.passkeys.options'))->assertRedirect(route('login'));
    }

    public function test_user_can_register_a_passkey(): void
    {
        $user = $this->localUser();

        $this->actingAs($user)
            ->post(route('profile.security.passkeys.store'), ['name' => 'YubiKey', 'credential_id' => 'cred-1'])
            ->assertRedirect(route('profile.security'));

        $this->assertDatabaseHas('webauthn_credentials', [
            'user_id' => $user->id, 'name' => 'YubiKey', 'credential_id' => 'cred-1',
        ]);
        $this->assertNotNull($user->fresh()->webauthn_user_handle);
        $this->assertDatabaseHas('audit_logs', ['event' => 'webauthn.registered', 'user_id' => $user->id]);
    }

    public function test_first_passkey_generates_recovery_codes(): void
    {
        $user = $this->localUser();

        $this->actingAs($user)->post(route('profile.security.passkeys.store'), [
            'name' => 'Key', 'credential_id' => 'cred-x',
        ])->assertSessionHas('security.recovery_codes');

        $this->assertNotEmpty($user->fresh()->twoFactor->recoveryCodes());
    }

    public function test_user_cannot_delete_another_users_passkey(): void
    {
        $owner = $this->localUser();
        $other = $this->localUser();
        $credential = $owner->webauthnCredentials()->create([
            'name' => 'K', 'credential_id' => 'c1', 'public_key' => 'x',
        ]);

        $this->actingAs($other)
            ->delete(route('profile.security.passkeys.destroy', $credential), ['current_password' => 'Password123!'])
            ->assertForbidden();

        $this->assertDatabaseHas('webauthn_credentials', ['id' => $credential->id]);
    }

    public function test_deleting_passkey_requires_current_password_for_local_accounts(): void
    {
        $user = $this->localUser();
        $credential = $user->webauthnCredentials()->create([
            'name' => 'K', 'credential_id' => 'c1', 'public_key' => 'x',
        ]);

        $this->actingAs($user)
            ->from(route('profile.security'))
            ->delete(route('profile.security.passkeys.destroy', $credential), ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');

        $this->assertDatabaseHas('webauthn_credentials', ['id' => $credential->id]);
    }

    // ----- Passwortlose Anmeldung -------------------------------------

    private function userWithPasskey(string $authSource = 'local'): User
    {
        $user = User::factory()->create([
            'auth_source' => $authSource, 'is_active' => true,
            'password' => Hash::make('Password123!'),
            'webauthn_user_handle' => 'handle-'.$authSource,
        ]);
        $user->webauthnCredentials()->create([
            'name' => 'Key', 'credential_id' => 'pk-'.$authSource, 'public_key' => 'x', 'discoverable' => true,
        ]);

        return $user;
    }

    public function test_passwordless_login_succeeds_for_local_user_by_default(): void
    {
        $user = $this->userWithPasskey();

        $this->post(route('login.passkey.verify'), ['credential_id' => 'pk-local'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_passwordless_login_disabled_for_local_returns_error(): void
    {
        SystemSetting::set('passkey_passwordless_local_enabled', '0');
        SystemSetting::set('passkey_passwordless_ad_enabled', '1');
        $user = $this->userWithPasskey();

        $this->from(route('login'))
            ->post(route('login.passkey.verify'), ['credential_id' => 'pk-local'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_passwordless_endpoints_404_when_completely_disabled(): void
    {
        SystemSetting::set('passkey_passwordless_local_enabled', '0');
        SystemSetting::set('passkey_passwordless_ad_enabled', '0');

        $this->post(route('login.passkey.options'))->assertNotFound();
        $this->post(route('login.passkey.verify'), ['credential_id' => 'x'])->assertNotFound();
    }

    public function test_ad_passwordless_login_is_disabled_by_default(): void
    {
        SystemSetting::set('passkey_passwordless_local_enabled', '1');
        $user = $this->userWithPasskey('active_directory');

        $this->from(route('login'))
            ->post(route('login.passkey.verify'), ['credential_id' => 'pk-active_directory'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_use_passwordless_login(): void
    {
        $user = $this->userWithPasskey();
        $user->forceFill(['is_active' => false])->save();

        $this->from(route('login'))
            ->post(route('login.passkey.verify'), ['credential_id' => 'pk-local'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    // ----- Passkey als zweiter Faktor -------------------------------

    public function test_password_login_with_only_a_passkey_redirects_to_challenge(): void
    {
        $user = $this->userWithPasskey();

        $this->post(route('login.attempt'), ['username' => $user->username, 'password' => 'Password123!'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        $this->post(route('two-factor.webauthn'), ['credential_id' => 'pk-local'])->assertRedirect();
        $this->assertAuthenticatedAs($user->fresh());
    }

    // ----- Admin ---------------------------------------------------

    public function test_admin_can_reset_a_users_two_factor(): void
    {
        $admin = User::factory()->create([
            'auth_source' => 'local', 'is_admin' => true, 'is_active' => true, 'password' => Hash::make('x'),
        ]);
        $user = $this->userWithPasskey();
        $user->twoFactor()->create(['totp_secret' => 'S', 'totp_confirmed_at' => now()]);

        $this->actingAs($admin)
            ->post(route('admin.users.two-factor.reset', $user))
            ->assertRedirect();

        $this->assertDatabaseMissing('webauthn_credentials', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('two_factor_credentials', ['user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.two_factor_reset']);
    }

    public function test_admin_can_remove_a_single_passkey(): void
    {
        $admin = User::factory()->create([
            'auth_source' => 'local', 'is_admin' => true, 'is_active' => true, 'password' => Hash::make('x'),
        ]);
        $user = $this->userWithPasskey();
        $credential = $user->webauthnCredentials()->first();

        $this->actingAs($admin)
            ->delete(route('admin.users.webauthn.destroy', [$user, $credential]))
            ->assertRedirect();

        $this->assertDatabaseMissing('webauthn_credentials', ['id' => $credential->id]);
    }

    public function test_passwordless_settings_are_persisted(): void
    {
        $admin = User::factory()->create([
            'auth_source' => 'local', 'is_admin' => true, 'is_active' => true, 'password' => Hash::make('x'),
        ]);

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'system_name' => 'Test', 'base_url' => 'https://example.test', 'timezone' => 'UTC',
            'locale' => 'de', 'session_lifetime' => 30,
            'passkey_passwordless_local_enabled' => '0',
            'passkey_passwordless_ad_enabled' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(SecuritySettings::passwordlessLocalEnabled());
        $this->assertTrue(SecuritySettings::passwordlessAdEnabled());
    }
}
