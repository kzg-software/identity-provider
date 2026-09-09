<?php

namespace Tests\Feature;

use App\Models\OidcKey;
use App\Models\SamlCertificate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Notifier;
use App\Support\SystemNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', '1');
    }

    public function test_bell_shows_unread_count_and_notifications_page_lists_them(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);

        Notifier::toUser($user, 'security.change', 'Passkey hinzugefügt', ['body' => 'Ein Passkey wurde ergänzt.']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Passkey hinzugefügt');

        $this->actingAs($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Passkey hinzugefügt')
            ->assertSee('Ein Passkey wurde ergänzt.');
    }

    public function test_opening_a_notification_marks_it_read_and_redirects(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);

        $note = Notifier::toUser($user, 'security.change', 'Test', ['action_url' => route('profile.security')]);

        $this->actingAs($user)->get(route('notifications.open', $note))
            ->assertredirect(route('profile.security'));

        $this->assertNotNull($note->fresh()->read_at);
    }

    public function test_mark_all_read(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);
        Notifier::toUser($user, 'security.change', 'A');
        Notifier::toUser($user, 'security.change', 'B');

        $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect();

        $this->assertSame(0, $user->notifications()->unread()->count());
    }

    public function test_a_user_cannot_touch_someone_elses_notification(): void
    {
        $a = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);
        $b = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);
        $note = Notifier::toUser($b, 'security.change', 'Fremd');

        $this->actingAs($a)->delete(route('notifications.destroy', $note))->assertNotFound();
        $this->assertDatabaseHas('user_notifications', ['id' => $note->id]);
    }

    public function test_dedupe_key_prevents_duplicates(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);

        Notifier::toUser($user, 'system.update', 'Update 1.2.3', ['dedupe_key' => 'sys:update:1.2.3']);
        Notifier::toUser($user, 'system.update', 'Update 1.2.3', ['dedupe_key' => 'sys:update:1.2.3']);

        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_system_sync_creates_and_resolves_admin_alerts(): void
    {
        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);
        Cache::put('schedule.heartbeat', now()->toDateTimeString(), 600);

        OidcKey::create([
            'kid' => 'k', 'algorithm' => 'RS256', 'public_key' => 'dummy',
            'private_key_encrypted' => 'dummy', 'is_active' => true, 'rotated_at' => now(),
        ]);

        $cert = SamlCertificate::create([
            'name' => 'Signing', 'type' => 'signing', 'certificate' => 'dummy',
            'private_key_encrypted' => 'dummy', 'fingerprint' => 'AA:BB', 'algorithm' => 'RSA-SHA256',
            'issued_at' => now()->subYear(), 'expires_at' => now()->addDays(5), 'is_active' => true,
        ]);

        SystemNotifications::sync();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $admin->id,
            'type' => 'system.expiry',
            'title' => 'SAML-Zertifikat läuft bald ab',
        ]);

        // Bedingung verschwindet -> ungelesene Systembenachrichtigung wird entfernt.
        $cert->update(['expires_at' => now()->addYears(3)]);
        Cache::forget('notifications:last_system_sync');
        SystemNotifications::sync();

        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $admin->id,
            'title' => 'SAML-Zertifikat läuft bald ab',
        ]);
    }

    public function test_old_notifications_are_pruned_by_retention_rules(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);

        $systemReadOld = Notifier::toUser($user, 'system.update', 'System, gelesen, 8 Tage');
        $systemReadOld->forceFill(['read_at' => now()->subDays(8)])->save();

        $systemReadFresh = Notifier::toUser($user, 'system.update', 'System, gelesen, 3 Tage');
        $systemReadFresh->forceFill(['read_at' => now()->subDays(3)])->save();

        $personalReadMid = Notifier::toUser($user, 'security.change', 'Persönlich, gelesen, 10 Tage');
        $personalReadMid->forceFill(['read_at' => now()->subDays(10)])->save();

        $personalReadOld = Notifier::toUser($user, 'security.change', 'Persönlich, gelesen, 40 Tage');
        $personalReadOld->forceFill(['read_at' => now()->subDays(40)])->save();

        $unreadVeryOld = Notifier::toUser($user, 'security.change', 'Ungelesen, 200 Tage');
        $unreadVeryOld->forceFill(['created_at' => now()->subDays(200)])->save();

        $unreadRecent = Notifier::toUser($user, 'security.change', 'Ungelesen, frisch');

        SystemNotifications::pruneReadNotifications();

        $this->assertDatabaseMissing('user_notifications', ['id' => $systemReadOld->id]);
        $this->assertDatabaseHas('user_notifications', ['id' => $systemReadFresh->id]);
        $this->assertDatabaseHas('user_notifications', ['id' => $personalReadMid->id]);
        $this->assertDatabaseMissing('user_notifications', ['id' => $personalReadOld->id]);
        $this->assertDatabaseMissing('user_notifications', ['id' => $unreadVeryOld->id]);
        $this->assertDatabaseHas('user_notifications', ['id' => $unreadRecent->id]);
    }

    public function test_regular_user_gets_no_system_alerts(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_admin' => false, 'is_active' => true]);
        Cache::put('schedule.heartbeat', now()->toDateTimeString(), 600);

        SystemNotifications::sync();

        $this->assertSame(0, $user->notifications()->count());
    }
}
