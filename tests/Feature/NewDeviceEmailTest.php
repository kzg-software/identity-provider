<?php

namespace Tests\Feature;

use App\Mail\SystemMail;
use App\Models\Notification;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NewDeviceEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', '1');
        // Damit Notifier::maybeSendEmail nicht an "Mail nicht konfiguriert" scheitert.
        SystemSetting::set('mail_enabled', '1');
        SystemSetting::set('mail_host', 'smtp.example.test');
        SystemSetting::set('mail_from_address', 'idp@example.test');
        Mail::fake();
    }

    private function user(): User
    {
        return User::factory()->create([
            'auth_source' => 'local',
            'is_active' => true,
            'email' => 'person@example.test',
            'password' => Hash::make('Password123!'),
        ]);
    }

    private function login(User $user, string $ua): void
    {
        $this->withHeader('User-Agent', $ua)
            ->post(route('login.attempt'), ['username' => $user->username, 'password' => 'Password123!']);
    }

    public function test_first_login_from_a_device_notifies_the_user_once(): void
    {
        $user = $this->user();

        $this->login($user, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36');

        $this->assertDatabaseHas('user_login_devices', ['user_id' => $user->id]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'type' => 'security.new_device',
        ]);
        Mail::assertQueued(SystemMail::class);
    }

    public function test_a_known_device_does_not_notify_again(): void
    {
        $user = $this->user();
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36';

        $this->login($user, $ua);
        $this->post(route('logout'));
        Mail::fake();

        // Gleicher Browser, andere Version -> gleiche Signatur -> keine neue Mail.
        $this->login($user, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0 Safari/537.36');

        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', 'security.new_device')->count());
        Mail::assertNothingQueued();
    }

    public function test_a_genuinely_new_device_notifies_again(): void
    {
        $user = $this->user();

        $this->login($user, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36');
        $this->post(route('logout'));

        $this->login($user, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile Safari/604.1');

        $this->assertSame(2, Notification::where('user_id', $user->id)->where('type', 'security.new_device')->count());
    }

    public function test_disabling_the_setting_stops_the_email_but_still_records_the_device(): void
    {
        SystemSetting::set('new_device_email_enabled', '0');
        $user = $this->user();

        $this->login($user, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36');

        $this->assertDatabaseHas('user_login_devices', ['user_id' => $user->id]);
        $this->assertDatabaseCount('user_notifications', 0);
        Mail::assertNothingQueued();
    }
}
