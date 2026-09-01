<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase5HardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sessions müssen für diese Tests tatsächlich in der DB landen, damit
        // der Widerruf-Mechanismus (Löschen aus `sessions`) geprüft werden kann.
        config(['session.driver' => 'database']);
    }

    private function installed(): void
    {
        SystemSetting::set('installed', '1');
    }

    private function localUser(bool $admin = false, string $username = 'jdoe'): User
    {
        return User::factory()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'auth_source' => 'local',
            'is_admin' => $admin,
            'is_active' => true,
            'password' => bcrypt('Password123!'),
        ]);
    }

    public function test_login_creates_a_tracked_user_session(): void
    {
        $this->installed();
        $user = $this->localUser();

        $this->post(route('login.attempt'), [
            'username' => 'jdoe',
            'password' => 'Password123!',
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('user_sessions', [
            'user_id' => $user->id,
            'login_method' => 'local',
        ]);

        $this->assertSame(1, UserSession::whereNull('revoked_at')->count());
    }

    public function test_revoking_a_session_actually_invalidates_the_laravel_session(): void
    {
        $this->installed();
        $user = $this->localUser();

        $this->post(route('login.attempt'), [
            'username' => 'jdoe',
            'password' => 'Password123!',
        ]);

        $userSession = UserSession::where('user_id', $user->id)->firstOrFail();
        $this->assertDatabaseHas('sessions', ['id' => $userSession->session_id]);

        $this->delete(route('profile.sessions.destroy', $userSession));

        $userSession->refresh();
        $this->assertNotNull($userSession->revoked_at);
        $this->assertDatabaseMissing('sessions', ['id' => $userSession->session_id]);
    }

    /**
     * Laravel's TestCase does NOT automatically forward Set-Cookie headers
     * from one $this->post()/get() call to the next (unlike a real browser)
     * - Auth::check() still appears to "work" across separate calls in the
     * same test only because the SessionGuard singleton caches the resolved
     * user in memory, but the actual session store/id is fresh per call
     * unless we carry the cookie ourselves. Needed for any test that must
     * prove behaviour keyed on the real session id (like SessionTracker
     * matching `sessions.id` <-> `user_sessions.session_id`).
     */
    private function carrySessionCookie(\Illuminate\Testing\TestResponse $response): void
    {
        $cookieName = config('session.cookie');

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $cookieName) {
                // The Set-Cookie value is already Laravel-encrypted (from
                // EncryptCookies on the way out). withUnencryptedCookie()
                // sends it through as-is; the normal cookie helpers would
                // encrypt it a SECOND time, producing a bogus value that
                // decrypts to garbage instead of our real session id.
                $this->withUnencryptedCookie($cookieName, $cookie->getValue());
            }
        }
    }

    public function test_logout_removes_the_session_from_meine_sitzungen(): void
    {
        $this->installed();
        $user = $this->localUser();

        $loginResponse = $this->post(route('login.attempt'), [
            'username' => 'jdoe',
            'password' => 'Password123!',
        ]);
        $this->carrySessionCookie($loginResponse);

        $userSession = UserSession::where('user_id', $user->id)->firstOrFail();

        $this->post(route('logout'));

        $userSession->refresh();
        $this->assertNotNull($userSession->revoked_at, 'logout() must mark the tracking row revoked, not just destroy the Laravel session.');
        $this->assertSame(0, UserSession::active()->where('user_id', $user->id)->count());
    }

    public function test_expired_session_disappears_from_meine_sitzungen_even_without_explicit_logout(): void
    {
        $this->installed();
        $user = $this->localUser();

        $this->post(route('login.attempt'), [
            'username' => 'jdoe',
            'password' => 'Password123!',
        ]);

        $userSession = UserSession::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(1, UserSession::active()->where('user_id', $user->id)->count());

        // Simulate a session that simply timed out (browser closed, no
        // logout click): still present in `sessions`, but its last_activity
        // is older than SESSION_LIFETIME - and revoked_at is still null,
        // since nothing ever explicitly revoked it.
        DB::table('sessions')->where('id', $userSession->session_id)->update([
            'last_activity' => now()->subMinutes((int) config('session.lifetime') + 5)->getTimestamp(),
        ]);

        $this->assertNull($userSession->fresh()->revoked_at);
        $this->assertSame(0, UserSession::active()->where('user_id', $user->id)->count());

        // Same thing as the controller actually queries for "Meine Sitzungen".
        $this->assertTrue($user->sessions()->active()->get()->isEmpty());
    }

    public function test_login_records_audit_log_entries(): void
    {
        $this->installed();
        $this->localUser();

        $this->post(route('login.attempt'), [
            'username' => 'jdoe',
            'password' => 'wrong-password',
        ]);

        $this->assertDatabaseHas('audit_logs', ['event' => 'login.failed']);

        $this->post(route('login.attempt'), [
            'username' => 'jdoe',
            'password' => 'Password123!',
        ]);

        $this->assertDatabaseHas('audit_logs', ['event' => 'login.success']);
    }

    public function test_timezone_from_system_settings_is_actually_applied(): void
    {
        $this->installed();
        SystemSetting::set('timezone', 'America/New_York');

        // AppServiceProvider::boot() only runs once per Application instance
        // (at bootstrap, before the test body executes), so simply making a
        // request here would still observe the app's original boot-time
        // timezone. We invoke boot() again directly - exactly what happens
        // on every fresh PHP-FPM request in real usage - to prove the actual
        // resolver + date_default_timezone_set() wiring works.
        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $this->assertSame('America/New_York', date_default_timezone_get());
        $this->assertSame('America/New_York', config('app.timezone'));
    }

    public function test_invalid_timezone_setting_does_not_break_the_app(): void
    {
        $this->installed();
        $fallback = config('app.timezone');
        SystemSetting::set('timezone', 'Not/A-Real-Timezone');

        (new \App\Providers\AppServiceProvider($this->app))->boot();

        // Falls back to the configured default instead of crashing or
        // adopting garbage input.
        $this->assertSame($fallback, date_default_timezone_get());
    }

    public function test_admin_settings_form_rejects_invalid_timezone(): void
    {
        $this->installed();
        $admin = $this->localUser(admin: true, username: 'admin');

        $this->actingAs($admin)->put(route('admin.settings.update'), [
            'system_name' => 'Auth',
            'base_url' => 'https://auth.example.test',
            'timezone' => 'Not/A-Real-Timezone',
            'locale' => 'de',
            'session_lifetime' => 120,
        ])->assertSessionHasErrors('timezone');
    }

    public function test_admin_can_impersonate_and_return(): void
    {
        $this->installed();
        $admin = $this->localUser(admin: true, username: 'admin');
        $target = $this->localUser(admin: false, username: 'plain');

        $this->actingAs($admin)
            ->post(route('admin.users.impersonate', $target))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($target->fresh());
        $this->assertEquals($admin->id, session('impersonate.admin_id'));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'admin.impersonate_start',
            'user_id' => $admin->id,
        ]);

        // The impersonated (non-admin) user must not gain admin access.
        $this->get(route('admin.users.index'))->assertForbidden();

        $this->post(route('impersonate.stop'))
            ->assertRedirect(route('admin.users.index'));

        $this->assertAuthenticatedAs($admin->fresh());
        $this->assertNull(session('impersonate.admin_id'));
    }

    public function test_admin_cannot_impersonate_another_admin_or_themselves(): void
    {
        $this->installed();
        $admin = $this->localUser(admin: true, username: 'admin');
        $otherAdmin = $this->localUser(admin: true, username: 'other-admin');

        $this->actingAs($admin)->post(route('admin.users.impersonate', $otherAdmin));
        $this->assertAuthenticatedAs($admin->fresh());

        $this->actingAs($admin)->post(route('admin.users.impersonate', $admin));
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_admin_can_upload_and_remove_logo_and_favicon(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $this->installed();
        $admin = $this->localUser(admin: true, username: 'admin');

        $logo = \Illuminate\Http\UploadedFile::fake()->image('logo.png', 200, 60);
        $this->actingAs($admin)->post(route('admin.settings.logo.upload'), ['logo' => $logo])
            ->assertRedirect();

        $logoPath = SystemSetting::get('logo_path');
        $this->assertNotEmpty($logoPath);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($logoPath);

        $this->actingAs($admin)->delete(route('admin.settings.logo.delete'))
            ->assertRedirect();

        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($logoPath);
        $this->assertEmpty(SystemSetting::get('logo_path'));

        $favicon = \Illuminate\Http\UploadedFile::fake()->image('favicon.png', 32, 32);
        $this->actingAs($admin)->post(route('admin.settings.favicon.upload'), ['favicon' => $favicon])
            ->assertRedirect();

        $faviconPath = SystemSetting::get('favicon_path');
        $this->assertNotEmpty($faviconPath);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($faviconPath);

        $this->actingAs($admin)->delete(route('admin.settings.favicon.delete'))
            ->assertRedirect();

        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($faviconPath);
    }

    public function test_favicon_upload_accepts_ico_files(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->installed();

        $admin = $this->localUser(admin: true, username: 'admin');

        $ico = \Illuminate\Http\UploadedFile::fake()->create('favicon.ico', 40, 'image/vnd.microsoft.icon');

        $this->actingAs($admin)->post(route('admin.settings.favicon.upload'), ['favicon' => $ico])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $faviconPath = SystemSetting::get('favicon_path');
        $this->assertNotEmpty($faviconPath);
        $this->assertStringEndsWith('.ico', $faviconPath);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($faviconPath);
    }

    public function test_admin_audit_log_page_is_restricted_to_local_admins(): void
    {
        $this->installed();
        $admin = $this->localUser(admin: true, username: 'admin');
        $nonAdmin = $this->localUser(admin: false, username: 'plain');

        AuditLog::record('login.success', $admin);

        $this->actingAs($admin)->get(route('admin.audit-log.index'))
            ->assertOk()
            ->assertSee('login.success');

        $this->actingAs($nonAdmin)->get(route('admin.audit-log.index'))
            ->assertForbidden();
    }

    public function test_health_check_page_reports_status_for_new_checks(): void
    {
        $this->installed();
        $admin = $this->localUser(admin: true);

        $this->actingAs($admin)->get(route('admin.status.index'))
            ->assertOk()
            ->assertSee('Queue')
            ->assertSee('Scheduler')
            ->assertSee('OAuth/OIDC Signing Key')
            ->assertSee('SAML Signing-Zertifikat');
    }
}
