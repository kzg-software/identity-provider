<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\TwoFactorCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', '1');
    }

    private function userWithTotp(array $recovery = []): User
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
            'recovery_codes' => $recovery ?: null,
        ]);

        return $user;
    }

    private function login(User $user): void
    {
        $this->post(route('login.attempt'), [
            'username' => $user->username,
            'password' => 'Password123!',
        ]);
    }

    public function test_password_login_with_totp_redirects_to_challenge_and_stays_guest(): void
    {
        $user = $this->userWithTotp();

        $this->post(route('login.attempt'), [
            'username' => $user->username,
            'password' => 'Password123!',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
    }

    public function test_user_without_two_factor_logs_in_directly(): void
    {
        SystemSetting::set('installed', '1');
        $user = User::factory()->create([
            'auth_source' => 'local', 'is_active' => true, 'password' => Hash::make('Password123!'),
        ]);

        $this->post(route('login.attempt'), [
            'username' => $user->username, 'password' => 'Password123!',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_correct_totp_code_completes_login(): void
    {
        $user = $this->userWithTotp();
        $this->login($user);

        $code = (new Google2FA)->getCurrentOtp($this->secret);

        $this->post(route('two-factor.totp'), ['code' => $code])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_wrong_totp_code_is_rejected(): void
    {
        $user = $this->userWithTotp();
        $this->login($user);

        $this->from(route('two-factor.challenge'))
            ->post(route('two-factor.totp'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_recovery_code_logs_in_and_is_consumed(): void
    {
        $user = $this->userWithTotp(['AAAAA-BBBBB', 'CCCCC-DDDDD']);
        $this->login($user);

        $this->post(route('two-factor.recovery'), ['recovery_code' => 'AAAAA-BBBBB'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user->fresh());

        $remaining = $user->twoFactor->fresh()->recoveryCodes();
        $this->assertSame(['CCCCC-DDDDD'], $remaining);
    }

    public function test_used_recovery_code_cannot_be_reused(): void
    {
        $user = $this->userWithTotp(['AAAAA-BBBBB']);
        $this->login($user);
        $this->post(route('two-factor.recovery'), ['recovery_code' => 'AAAAA-BBBBB']);
        $this->post(route('logout'));

        $this->login($user);
        $this->from(route('two-factor.challenge'))
            ->post(route('two-factor.recovery'), ['recovery_code' => 'AAAAA-BBBBB'])
            ->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_challenge_page_without_pending_state_redirects_to_login(): void
    {
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_expired_pending_state_redirects_to_login(): void
    {
        $user = $this->userWithTotp();
        $this->login($user);

        $this->travel(6)->minutes();

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }
}
