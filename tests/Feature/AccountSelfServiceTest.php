<?php

namespace Tests\Feature;

use App\Mail\SystemMail;
use App\Models\Application;
use App\Models\OauthClient;
use App\Models\OauthConsent;
use App\Models\OauthToken;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\MailSettings;
use App\Support\Notifier;
use App\Support\Secret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', '1');
    }

    private function configureMail(): void
    {
        SystemSetting::set('mail_enabled', '1');
        SystemSetting::set('mail_host', 'smtp.test');
    }

    // ----- Mein Account (Übersicht) --------------------------------------

    public function test_account_landing_shows_sections_with_counts(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);

        $this->actingAs($user)->get(route('profile.index'))
            ->assertOk()
            ->assertSee('Mein Account')
            ->assertSee('Sicherheit')
            ->assertSee('Verbundene Anwendungen')
            ->assertSee('Meine Sitzungen');
    }

    public function test_notification_category_toggle_is_saved_for_admin(): void
    {
        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true, 'email' => 'a@b.de']);

        $this->actingAs($admin)->get(route('profile.notifications'))->assertOk()->assertSee('Software-Updates');

        // Nichts angehakt -> alle einstellbaren Kategorien aus.
        $this->actingAs($admin)->post(route('profile.notifications.update'), [])->assertRedirect();
        $this->assertFalse($admin->fresh()->wantsEmailFor('updates'));
        $this->assertTrue($admin->fresh()->wantsEmailFor('account_security'));

        $this->actingAs($admin)->post(route('profile.notifications.update'), ['categories' => ['updates' => '1']])->assertRedirect();
        $this->assertTrue($admin->fresh()->wantsEmailFor('updates'));
        $this->assertFalse($admin->fresh()->wantsEmailFor('backup'));
    }

    public function test_non_admin_only_sees_locked_security_category(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_active' => true, 'email' => 'u@b.de']);

        $this->actingAs($user)->get(route('profile.notifications'))
            ->assertOk()
            ->assertSee('Konto-Sicherheit')
            ->assertSee('Immer aktiv')
            ->assertDontSee('Software-Updates');
    }

    // ----- E-Mail bei Benachrichtigungen --------------------------------

    public function test_security_notification_is_always_emailed(): void
    {
        Mail::fake();
        $this->configureMail();
        $user = User::factory()->create([
            'auth_source' => 'local', 'is_active' => true, 'email' => 'u@firma.de',
            'notification_email_prefs' => ['updates' => false],
            'password' => Hash::make('altes-Passwort-123'),
        ]);

        $this->actingAs($user)->post(route('profile.security.password'), [
            'current_password' => 'altes-Passwort-123',
            'password' => 'ganz-neues-Passwort-456',
            'password_confirmation' => 'ganz-neues-Passwort-456',
        ])->assertRedirect();

        Mail::assertQueued(SystemMail::class, fn ($m) => $m->hasTo('u@firma.de'));
    }

    public function test_non_security_notification_respects_preference(): void
    {
        Mail::fake();
        $this->configureMail();
        $user = User::factory()->create([
            'auth_source' => 'local', 'is_active' => true, 'email' => 'u@firma.de',
            'notification_email_prefs' => ['updates' => false],
        ]);

        Notifier::toUser($user, 'system.update', 'Update verfügbar', ['body' => 'Text']);
        Mail::assertNothingOutgoing();

        $user->update(['notification_email_prefs' => ['updates' => true]]);
        Notifier::toUser($user, 'system.update', 'Update verfügbar 2', ['dedupe_key' => 'x', 'body' => 'Text']);
        Mail::assertQueued(SystemMail::class);
    }

    // ----- Passwort vergessen -----------------------------------------

    public function test_forgot_password_always_shows_generic_message(): void
    {
        Mail::fake();
        $this->configureMail();
        User::factory()->create(['auth_source' => 'active_directory', 'is_active' => true, 'email' => 'ad-user@firma.de']);

        foreach (['gibtsnicht@x.de', 'ad-user@firma.de'] as $email) {
            $this->post(route('password.email'), ['email' => $email])
                ->assertSessionHas('status', fn ($s) => str_contains($s, 'Falls ein Konto'));
        }

        Mail::assertNothingOutgoing();
    }

    public function test_forgot_password_sends_link_for_local_account(): void
    {
        Mail::fake();
        $this->configureMail();
        User::factory()->create(['auth_source' => 'local', 'is_active' => true, 'email' => 'local@firma.de']);

        $this->post(route('password.email'), ['email' => 'local@firma.de'])->assertRedirect();

        Mail::assertQueued(SystemMail::class, fn ($m) => $m->hasTo('local@firma.de'));
    }

    public function test_reset_password_with_valid_token_changes_password(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'auth_source' => 'local', 'is_active' => true, 'email' => 'r@firma.de',
            'password' => Hash::make('alt'),
        ]);
        $token = Password::createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'r@firma.de',
            'password' => 'frisches-Passwort-999',
            'password_confirmation' => 'frisches-Passwort-999',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('frisches-Passwort-999', $user->fresh()->password));
        $this->assertDatabaseHas('user_notifications', ['user_id' => $user->id, 'title' => 'Passwort zurückgesetzt']);
    }

    public function test_reset_password_with_bad_token_is_rejected(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_active' => true, 'email' => 'r2@firma.de', 'password' => Hash::make('alt')]);

        $this->post(route('password.update'), [
            'token' => 'falsch',
            'email' => 'r2@firma.de',
            'password' => 'frisches-Passwort-999',
            'password_confirmation' => 'frisches-Passwort-999',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('alt', $user->fresh()->password));
    }

    // ----- Passwort ändern -------------------------------------------------

    public function test_local_user_can_change_password(): void
    {
        $user = User::factory()->create([
            'auth_source' => 'local',
            'is_active' => true,
            'password' => Hash::make('altes-Passwort-123'),
        ]);

        $this->actingAs($user)->post(route('profile.security.password'), [
            'current_password' => 'altes-Passwort-123',
            'password' => 'ganz-neues-Passwort-456',
            'password_confirmation' => 'ganz-neues-Passwort-456',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('ganz-neues-Passwort-456', $user->fresh()->password));
        $this->assertDatabaseHas('user_notifications', ['user_id' => $user->id, 'title' => 'Passwort geändert']);
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'auth_source' => 'local',
            'is_active' => true,
            'password' => Hash::make('altes-Passwort-123'),
        ]);

        $this->actingAs($user)->post(route('profile.security.password'), [
            'current_password' => 'falsch',
            'password' => 'ganz-neues-Passwort-456',
            'password_confirmation' => 'ganz-neues-Passwort-456',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('altes-Passwort-123', $user->fresh()->password));
    }

    public function test_directory_user_cannot_change_password(): void
    {
        $user = User::factory()->create(['auth_source' => 'active_directory', 'is_active' => true]);

        $this->actingAs($user)->post(route('profile.security.password'), [
            'current_password' => 'x',
            'password' => 'ganz-neues-Passwort-456',
            'password_confirmation' => 'ganz-neues-Passwort-456',
        ])->assertForbidden();
    }

    // ----- Verbundene Anwendungen ---------------------------------------

    private function makeClient(): OauthClient
    {
        $application = Application::create([
            'name' => 'Portal-App',
            'slug' => 'portal-app-'.Str::random(6),
            'is_active' => true,
        ]);
        $provider = $this->linkProvider($application, 'oidc', 'Portal-App');

        return OauthClient::create([
            'provider_id' => $provider->id,
            'name' => 'Portal-App',
            'client_id' => (string) Str::uuid(),
            'client_secret' => 'secret',
            'is_active' => true,
        ]);
    }

    public function test_connected_apps_lists_granted_app_and_revoke_cuts_access(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);
        $client = $this->makeClient();

        OauthConsent::create([
            'user_id' => $user->id,
            'oauth_client_id' => $client->id,
            'scopes' => ['openid', 'profile'],
            'granted_at' => now(),
        ]);
        $token = OauthToken::create([
            'user_id' => $user->id,
            'oauth_client_id' => $client->id,
            'type' => 'access_token',
            'identifier' => Str::random(20),
            'token_hash' => Str::random(40),
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($user)->get(route('profile.apps'))
            ->assertOk()
            ->assertSee('Portal-App');

        $this->actingAs($user)->delete(route('profile.apps.destroy', $client->id))->assertRedirect();

        $this->assertNotNull($user->oauthConsents()->first()->revoked_at);
        $this->assertTrue($token->fresh()->revoked);
    }

    public function test_revoking_does_not_touch_other_users_grants(): void
    {
        $me = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);
        $other = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);
        $client = $this->makeClient();

        $otherToken = OauthToken::create([
            'user_id' => $other->id,
            'oauth_client_id' => $client->id,
            'type' => 'access_token',
            'identifier' => Str::random(20),
            'token_hash' => Str::random(40),
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($me)->delete(route('profile.apps.destroy', $client->id))->assertRedirect();

        $this->assertFalse($otherToken->fresh()->revoked);
    }

    // ----- E-Mail-Einstellungen ---------------------------------------

    public function test_admin_saves_smtp_settings_with_encrypted_password(): void
    {
        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);

        $this->actingAs($admin)->put(route('admin.settings.update'), $this->baseSettings([
            'mail_enabled' => '1',
            'mail_host' => 'smtp.firma.de',
            'mail_port' => '587',
            'mail_encryption' => 'starttls',
            'mail_username' => 'no-reply@firma.de',
            'mail_password' => 'geheim123',
            'mail_from_address' => 'no-reply@firma.de',
            'mail_from_name' => 'Firma Login',
        ]))->assertRedirect();

        $this->assertSame('smtp.firma.de', SystemSetting::get('mail_host'));
        $this->assertSame('geheim123', Secret::decrypt(SystemSetting::get('mail_password')));
        $this->assertTrue(MailSettings::configured());

        MailSettings::apply();
        $this->assertSame('smtp.firma.de', config('mail.mailers.smtp.host'));
        $this->assertSame('smtp', config('mail.default'));
    }

    public function test_smtp_password_is_kept_when_field_left_blank(): void
    {
        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);
        SystemSetting::set('mail_password', Secret::encrypt('behalten'));

        $this->actingAs($admin)->put(route('admin.settings.update'), $this->baseSettings([
            'mail_enabled' => '1',
            'mail_host' => 'smtp.firma.de',
            'mail_password' => '',
        ]))->assertRedirect();

        $this->assertSame('behalten', Secret::decrypt(SystemSetting::get('mail_password')));
    }

    public function test_test_mail_requires_configured_smtp(): void
    {
        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin.settings.test-mail'), ['test_email' => 'a@b.de'])
            ->assertSessionHas('error');
    }

    public function test_system_mail_renders_html_and_text_with_branding(): void
    {
        SystemSetting::set('system_name', 'Firma Login');

        $mail = new SystemMail(
            subjectLine: 'Betreff',
            heading: 'Der E-Mail-Versand funktioniert',
            body: ['Erste Zeile.', 'Zweite Zeile.'],
            actionUrl: 'https://login.firma.de/ziel',
            actionLabel: 'Öffnen',
        );

        $html = $mail->render();
        $this->assertStringContainsString('Der E-Mail-Versand funktioniert', $html);
        $this->assertStringContainsString('Firma Login', $html);
        $this->assertStringContainsString('https://login.firma.de/ziel', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertSame('Betreff', $mail->envelope()->subject);

        $text = view('emails.system_plain', [
            'systemName' => 'Firma Login',
            'heading' => 'Der E-Mail-Versand funktioniert',
            'body' => ['Erste Zeile.'],
            'actionUrl' => null,
            'actionLabel' => null,
        ])->render();
        $this->assertStringContainsString('Erste Zeile.', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    /** @return array<string, mixed> */
    private function baseSettings(array $overrides = []): array
    {
        return array_merge([
            'system_name' => 'Login',
            'base_url' => 'https://login.firma.de',
            'timezone' => 'Europe/Berlin',
            'locale' => 'de',
            'session_lifetime' => 60,
        ], $overrides);
    }
}
