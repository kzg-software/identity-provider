<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\CustomCss;
use App\Support\LoginBackgrounds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Issue #7: mehrere (wechselnde) Login-Hintergründe und eigenes CSS.
 */
class LoginCustomizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        SystemSetting::set('installed', '1');
        $this->admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);
    }

    private function settings(array $extra = []): array
    {
        return array_merge([
            'system_name' => 'Auth',
            'base_url' => 'https://auth.example.test',
            'timezone' => 'Europe/Berlin',
            'locale' => 'de',
            'session_lifetime' => 120,
        ], $extra);
    }

    private function uploadBackgrounds(int $count): void
    {
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $files[] = UploadedFile::fake()->image("bg{$i}.jpg", 1920, 1080);
        }

        $this->actingAs($this->admin)
            ->post(route('admin.settings.login-background.upload'), ['login_background' => $files])
            ->assertSessionHasNoErrors();
    }

    // ----- Hintergrundbilder ---------------------------------------------------

    public function test_admin_can_upload_several_backgrounds_at_once(): void
    {
        $this->uploadBackgrounds(3);

        $paths = LoginBackgrounds::paths();
        $this->assertCount(3, $paths);
        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
        }
        $this->assertSame($paths[0], SystemSetting::get('login_background_path'));
    }

    public function test_single_file_upload_still_works_and_appends(): void
    {
        $this->uploadBackgrounds(1);

        $this->actingAs($this->admin)->post(route('admin.settings.login-background.upload'), [
            'login_background' => UploadedFile::fake()->image('single.png'),
        ])->assertSessionHasNoErrors();

        $this->assertCount(2, LoginBackgrounds::paths());
    }

    public function test_upload_is_limited_to_the_maximum_number_of_images(): void
    {
        $this->uploadBackgrounds(LoginBackgrounds::MAX_IMAGES);

        $this->actingAs($this->admin)->post(route('admin.settings.login-background.upload'), [
            'login_background' => [UploadedFile::fake()->image('zu-viel.jpg')],
        ])->assertSessionHasErrors('login_background');

        $this->assertCount(LoginBackgrounds::MAX_IMAGES, LoginBackgrounds::paths());
    }

    public function test_non_images_are_rejected_and_nothing_is_stored(): void
    {
        $this->actingAs($this->admin)->post(route('admin.settings.login-background.upload'), [
            'login_background' => [
                UploadedFile::fake()->image('ok.jpg'),
                UploadedFile::fake()->create('boese.php', 10, 'application/x-php'),
            ],
        ])->assertSessionHasErrors('login_background');

        $this->assertSame([], LoginBackgrounds::paths());
    }

    public function test_single_image_can_be_removed_and_promoted(): void
    {
        $this->uploadBackgrounds(3);
        [$first, $second, $third] = LoginBackgrounds::paths();

        $this->actingAs($this->admin)
            ->post(route('admin.settings.login-background.promote', basename($third)))
            ->assertSessionHasNoErrors();
        $this->assertSame([$third, $first, $second], LoginBackgrounds::paths());

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.login-background.destroy-image', basename($first)))
            ->assertSessionHasNoErrors();
        $this->assertSame([$third, $second], LoginBackgrounds::paths());
        Storage::disk('public')->assertMissing($first);

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.login-background.destroy-image', 'gibt-es-nicht.jpg'))
            ->assertNotFound();
    }

    public function test_remove_all_deletes_every_image(): void
    {
        $this->uploadBackgrounds(2);
        $paths = LoginBackgrounds::paths();

        $this->actingAs($this->admin)->delete(route('admin.settings.login-background.delete'))->assertSessionHasNoErrors();

        $this->assertSame([], LoginBackgrounds::paths());
        $this->assertSame('', SystemSetting::get('login_background_path'));
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_legacy_single_background_is_still_used(): void
    {
        SystemSetting::set('login_background_path', 'branding/alt.jpg');

        $this->assertSame(['branding/alt.jpg'], LoginBackgrounds::paths());
        $this->get(route('login'))->assertOk()->assertSee('branding/alt.jpg', false);
    }

    public function test_non_admins_cannot_manage_backgrounds(): void
    {
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true, 'auth_source' => 'local']);

        $this->actingAs($user)->post(route('admin.settings.login-background.upload'), [
            'login_background' => [UploadedFile::fake()->image('x.jpg')],
        ])->assertForbidden();

        $this->assertSame([], LoginBackgrounds::paths());
    }

    public function test_background_mode_settings_are_saved(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->settings([
            'login_background_mode' => 'slideshow',
            'login_background_interval' => 20,
            'login_background_overlay' => 0,
        ]))->assertSessionHasNoErrors();

        $this->assertSame('slideshow', LoginBackgrounds::mode());
        $this->assertSame(20, LoginBackgrounds::interval());
        $this->assertSame(0, LoginBackgrounds::overlay());

        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->settings([
            'login_background_mode' => 'wild',
            'login_background_interval' => 1,
        ]))->assertSessionHasErrors(['login_background_mode', 'login_background_interval']);
    }

    public function test_slideshow_renders_all_images_but_only_loads_the_first_right_away(): void
    {
        $this->uploadBackgrounds(3);
        SystemSetting::set('login_background_mode', 'slideshow');
        SystemSetting::set('login_background_interval', '7');
        auth()->logout();

        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('data-idp-slideshow', $html);
        $this->assertStringContainsString('data-interval="7000"', $html);
        $this->assertSame(3, substr_count($html, 'idp-login-bg-image'));
        $this->assertSame(2, substr_count($html, 'data-src='));
    }

    public function test_random_and_fixed_modes_render_exactly_one_image(): void
    {
        $this->uploadBackgrounds(3);
        $first = basename(LoginBackgrounds::paths()[0]);
        auth()->logout();

        foreach (['random', 'daily', 'fixed'] as $mode) {
            SystemSetting::set('login_background_mode', $mode);
            $html = $this->get(route('login'))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'idp-login-bg-image'), $mode);
            $this->assertStringNotContainsString('data-idp-slideshow', $html, $mode);
        }

        $this->assertStringContainsString($first, $html, 'fixed zeigt das erste Bild');
    }

    public function test_overlay_strength_is_applied(): void
    {
        $this->uploadBackgrounds(1);
        SystemSetting::set('login_background_overlay', '65');
        auth()->logout();

        $this->get(route('login'))->assertOk()->assertSee('rgba(0,0,0,0.65)', false);

        SystemSetting::set('login_background_overlay', '0');
        $this->get(route('login'))->assertOk()->assertDontSee('idp-login-bg-overlay', false);
    }

    // ----- Eigenes CSS ---------------------------------------------------------

    public function test_custom_css_is_saved_and_rendered_on_the_login_page(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->settings([
            'custom_css' => ".idp-login-card { border-radius: 2rem; }\r\n",
            'custom_css_scope' => 'login',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('.idp-login-card { border-radius: 2rem; }', SystemSetting::get('custom_css'));

        auth()->logout();
        $this->get(route('login'))->assertOk()
            ->assertSee('<style id="custom-css">.idp-login-card { border-radius: 2rem; }</style>', false);
    }

    public function test_custom_css_cannot_break_out_of_the_style_block(): void
    {
        foreach ([
            '</style><script>alert(1)</script>',
            'a { color: red } </STYLE ><img src=x onerror=alert(1)>',
            '.a > .b { color: red }',
            'body { background: url("javascript:alert(1)") }',
            'body { width: expression(alert(1)) }',
        ] as $css) {
            $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->settings([
                'custom_css' => $css,
            ]))->assertSessionHasErrors('custom_css');
        }

        $this->assertNull(SystemSetting::get('custom_css'));
    }

    public function test_unsafe_css_that_reached_the_database_is_never_rendered(): void
    {
        // z. B. über eine eingespielte Sicherung an der Prüfung vorbei
        SystemSetting::set('custom_css', '</style><script>alert(1)</script>');

        $this->get(route('login'))->assertOk()->assertDontSee('alert(1)', false);
    }

    public function test_custom_css_scope_controls_where_it_is_loaded(): void
    {
        SystemSetting::set('custom_css', '.marker-css { color: red; }');

        SystemSetting::set('custom_css_scope', 'login');
        $this->get(route('login'))->assertSee('marker-css', false);
        $this->actingAs($this->admin)->get(route('admin.settings.edit'))->assertOk()
            ->assertDontSee('<style id="custom-css">', false);
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertDontSee('<style id="custom-css">', false);

        SystemSetting::set('custom_css_scope', 'portal');
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee('<style id="custom-css">', false);
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee('<style id="custom-css">', false);

        SystemSetting::set('custom_css_scope', 'all');
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSee('<style id="custom-css">', false);

        // Notbremse
        $this->actingAs($this->admin)->get(route('admin.dashboard', ['custom_css' => 'off']))->assertOk()
            ->assertDontSee('<style id="custom-css">', false);
    }

    public function test_custom_css_is_not_written_verbatim_to_the_audit_log(): void
    {
        $css = '.idp-login-card { box-shadow: none; }';

        $this->actingAs($this->admin)->put(route('admin.settings.update'), $this->settings([
            'custom_css' => $css,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('audit_logs', ['event' => 'admin.settings_updated', 'metadata->custom_css' => $css]);
    }

    public function test_settings_page_shows_new_controls(): void
    {
        $this->uploadBackgrounds(2);

        $this->actingAs($this->admin)->get(route('admin.settings.edit'))->assertOk()
            ->assertSee('name="login_background_mode"', false)
            ->assertSee('name="login_background_overlay"', false)
            ->assertSee('name="custom_css"', false)
            ->assertSee('name="custom_css_scope"', false)
            ->assertSee('name="login_background[]"', false)
            ->assertSee('Als erstes');
    }

    public function test_problem_detection_allows_normal_css(): void
    {
        $this->assertNull(CustomCss::problem(<<<'CSS'
            @media (min-width: 640px) { .idp-login-card { border-radius: 1rem; } }
            html.dark .idp-login-card { background: #111 url('/storage/branding/x.png') no-repeat; }
            .idp-login-footer a:hover { text-decoration: underline; }
            CSS));
    }
}
