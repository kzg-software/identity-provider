<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\OauthClient;
use App\Models\OauthToken;
use App\Models\SystemSetting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Nach einem Passwortwechsel (selbst, durch die Administration oder per
 * „Passwort vergessen") dürfen bisherige Sitzungen und OAuth-Tokens nicht
 * weiter gültig sein.
 */
class PasswordChangeRevokesAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', '1');
    }

    private function localUser(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'auth_source' => 'local',
            'is_active' => true,
            'password' => Hash::make('altes-Passwort-123'),
        ]);
    }

    /** @return array{session: UserSession, token: OauthToken} */
    private function giveAccess(User $user, string $sessionId): array
    {
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);

        $session = UserSession::create([
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'login_at' => now(),
            'last_activity_at' => now(),
        ]);

        $application = Application::create([
            'name' => 'Portal-App',
            'slug' => 'portal-app-'.Str::random(6),
            'is_active' => true,
        ]);
        $provider = $this->linkProvider($application, 'oidc', 'Portal-App');
        $client = OauthClient::create([
            'provider_id' => $provider->id,
            'name' => 'Portal-App',
            'client_id' => (string) Str::uuid(),
            'client_secret' => 'secret',
            'is_active' => true,
        ]);

        $token = OauthToken::create([
            'user_id' => $user->id,
            'oauth_client_id' => $client->id,
            'type' => 'refresh_token',
            'identifier' => Str::random(20),
            'token_hash' => Str::random(40),
            'revoked' => false,
            'expires_at' => now()->addDays(30),
        ]);

        return ['session' => $session, 'token' => $token];
    }

    public function test_own_password_change_ends_other_sessions_and_revokes_tokens(): void
    {
        $user = $this->localUser();
        ['session' => $other, 'token' => $token] = $this->giveAccess($user, 'andere-sitzung');

        $this->actingAs($user)->post(route('profile.security.password'), [
            'current_password' => 'altes-Passwort-123',
            'password' => 'ganz-neues-Passwort-456',
            'password_confirmation' => 'ganz-neues-Passwort-456',
        ])->assertRedirect();

        $this->assertNotNull($other->fresh()->revoked_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'andere-sitzung']);
        $this->assertTrue($token->fresh()->revoked);
    }

    public function test_admin_password_reset_ends_sessions_tokens_and_trusted_devices(): void
    {
        $admin = $this->localUser(['is_admin' => true]);
        $user = $this->localUser();
        ['session' => $session, 'token' => $token] = $this->giveAccess($user, 'sitzung-des-nutzers');
        TrustedDevice::create([
            'user_id' => $user->id,
            'token_hash' => Str::random(64),
            'label' => 'Laptop',
            'expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($admin)->post(route('admin.users.reset-password', $user), [
            'password' => 'vom-admin-gesetzt-789',
            'password_confirmation' => 'vom-admin-gesetzt-789',
        ])->assertRedirect();

        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertTrue($token->fresh()->revoked);
        $this->assertSame(0, $user->trustedDevices()->count());
    }

    public function test_forgot_password_reset_ends_sessions_and_tokens(): void
    {
        SystemSetting::set('mail_enabled', '1');
        SystemSetting::set('mail_host', 'smtp.test');

        $user = $this->localUser(['email' => 'r@firma.de']);
        ['session' => $session, 'token' => $token] = $this->giveAccess($user, 'angreifer-sitzung');

        $this->post(route('password.update'), [
            'token' => Password::createToken($user),
            'email' => 'r@firma.de',
            'password' => 'frisches-Passwort-999',
            'password_confirmation' => 'frisches-Passwort-999',
        ])->assertRedirect(route('login'));

        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertTrue($token->fresh()->revoked);
    }

    public function test_other_users_access_is_not_touched(): void
    {
        $user = $this->localUser();
        $bystander = $this->localUser();
        ['session' => $bystanderSession, 'token' => $bystanderToken] = $this->giveAccess($bystander, 'fremde-sitzung');

        $this->actingAs($user)->post(route('profile.security.password'), [
            'current_password' => 'altes-Passwort-123',
            'password' => 'ganz-neues-Passwort-456',
            'password_confirmation' => 'ganz-neues-Passwort-456',
        ])->assertRedirect();

        $this->assertNull($bystanderSession->fresh()->revoked_at);
        $this->assertFalse($bystanderToken->fresh()->revoked);
    }
}
