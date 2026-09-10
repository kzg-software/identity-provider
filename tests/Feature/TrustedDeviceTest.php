<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\TrustedDevice;
use App\Models\TwoFactorCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TrustedDeviceTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', '1');
    }

    private function userWithTotp(): User
    {
        $user = User::factory()->create([
            'auth_source' => 'local',
            'is_active' => true,
            'password' => Hash::make('Password123!'),
        ]);

        $this->secret = (new Google2FA)->generateSecretKey();

        TwoFactorCredential::create([
            'user_id' => $user->id,
            'totp_secret' => $this->secret,
            'totp_confirmed_at' => now(),
        ]);

        return $user;
    }

    private function login(User $user): void
    {
        $this->post(route('login.attempt'), ['username' => $user->username, 'password' => 'Password123!']);
    }

    public function test_checking_trust_this_device_stores_a_device_and_sets_a_cookie(): void
    {
        $user = $this->userWithTotp();
        $this->login($user);

        $response = $this->post(route('two-factor.totp'), [
            'code' => (new Google2FA)->getCurrentOtp($this->secret),
            'trust_device' => '1',
        ]);

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertDatabaseHas('trusted_devices', ['user_id' => $user->id]);

        $cookie = $response->getCookie('trusted_device');
        $this->assertNotNull($cookie);
        $this->assertSame(
            hash('sha256', $cookie->getValue()),
            TrustedDevice::where('user_id', $user->id)->value('token_hash')
        );
    }

    public function test_without_the_checkbox_no_device_is_trusted(): void
    {
        $user = $this->userWithTotp();
        $this->login($user);

        $this->post(route('two-factor.totp'), [
            'code' => (new Google2FA)->getCurrentOtp($this->secret),
        ])->assertRedirect();

        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_a_trusted_device_skips_the_two_factor_challenge_on_the_next_login(): void
    {
        $user = $this->userWithTotp();
        $this->login($user);

        $token = $this->post(route('two-factor.totp'), [
            'code' => (new Google2FA)->getCurrentOtp($this->secret),
            'trust_device' => '1',
        ])->getCookie('trusted_device')->getValue();

        $this->post(route('logout'));
        $this->assertGuest();

        $this->withCookie('trusted_device', $token)
            ->post(route('login.attempt'), ['username' => $user->username, 'password' => 'Password123!'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_an_expired_trusted_device_still_asks_for_the_second_factor(): void
    {
        $user = $this->userWithTotp();
        $this->login($user);

        $token = $this->post(route('two-factor.totp'), [
            'code' => (new Google2FA)->getCurrentOtp($this->secret),
            'trust_device' => '1',
        ])->getCookie('trusted_device')->getValue();

        TrustedDevice::where('user_id', $user->id)->update(['expires_at' => now()->subDay()]);
        $this->post(route('logout'));

        $this->withCookie('trusted_device', $token)
            ->post(route('login.attempt'), ['username' => $user->username, 'password' => 'Password123!'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
    }

    public function test_changing_the_password_revokes_all_trusted_devices(): void
    {
        $user = $this->userWithTotp();
        $this->login($user);
        $this->post(route('two-factor.totp'), [
            'code' => (new Google2FA)->getCurrentOtp($this->secret),
            'trust_device' => '1',
        ]);

        $this->assertDatabaseCount('trusted_devices', 1);

        $this->actingAs($user->fresh())->post(route('profile.security.password'), [
            'current_password' => 'Password123!',
            'password' => 'BrandNewPass456!',
            'password_confirmation' => 'BrandNewPass456!',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('trusted_devices', 0);
    }

    public function test_the_option_can_be_switched_off_system_wide(): void
    {
        SystemSetting::set('trusted_device_enabled', '0');
        $user = $this->userWithTotp();
        $this->login($user);

        $this->get(route('two-factor.challenge'))
            ->assertOk()
            ->assertDontSee('Diesem Gerät vertrauen');

        $this->post(route('two-factor.totp'), [
            'code' => (new Google2FA)->getCurrentOtp($this->secret),
            'trust_device' => '1',
        ]);

        $this->assertDatabaseCount('trusted_devices', 0);
    }
}
