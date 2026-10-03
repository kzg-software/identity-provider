<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SvgSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SvgLogoUploadTest extends TestCase
{
    use RefreshDatabase;

    private const EVIL = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="alert(1)"><script>alert(1)</script><foreignObject><div>x</div></foreignObject><a xlink:href="javascript:alert(1)"><rect width="5" height="5" fill="red" onclick="x()"/></a><use href="http://evil.test/x.svg#a"/></svg>';

    public function test_sanitizer_strips_active_content_but_keeps_shapes(): void
    {
        $out = SvgSanitizer::sanitize(self::EVIL);

        $this->assertNotNull($out);
        $this->assertStringContainsString('<rect', $out);
        $this->assertStringContainsString('viewBox', $out);
        $this->assertStringNotContainsStringIgnoringCase('script', $out);
        $this->assertStringNotContainsStringIgnoringCase('foreignObject', $out);
        $this->assertStringNotContainsStringIgnoringCase('onload', $out);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $out);
        $this->assertStringNotContainsStringIgnoringCase('javascript', $out);
        $this->assertStringNotContainsString('evil.test', $out);
    }

    public function test_sanitizer_rejects_non_svg_and_entities(): void
    {
        $this->assertNull(SvgSanitizer::sanitize('<html><body/></html>'));
        $this->assertNull(SvgSanitizer::sanitize('<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'));
    }

    public function test_admin_can_upload_svg_banner_and_it_is_sanitized(): void
    {
        Storage::fake('public');
        SystemSetting::set('installed', '1');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);

        $file = UploadedFile::fake()->createWithContent('logo.svg', self::EVIL);

        $this->actingAs($admin)->post(route('admin.settings.logo.upload'), ['logo' => $file])
            ->assertSessionHasNoErrors();

        $path = SystemSetting::get('logo_path');
        $this->assertStringEndsWith('.svg', $path);
        $stored = Storage::disk('public')->get($path);
        $this->assertStringContainsString('<rect', $stored);
        $this->assertStringNotContainsStringIgnoringCase('script', $stored);
    }

    public function test_favicon_and_login_background_accept_sanitized_svg(): void
    {
        Storage::fake('public');
        SystemSetting::set('installed', '1');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);

        $this->actingAs($admin)->post(route('admin.settings.favicon.upload'), [
            'favicon' => UploadedFile::fake()->createWithContent('f.svg', self::EVIL),
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.settings.login-background.upload'), [
            'login_background' => UploadedFile::fake()->createWithContent('b.svg', self::EVIL),
        ])->assertSessionHasNoErrors();

        foreach (['favicon_path', 'login_background_path'] as $key) {
            $stored = Storage::disk('public')->get(SystemSetting::get($key));
            $this->assertStringContainsString('<rect', $stored);
            $this->assertStringNotContainsStringIgnoringCase('script', $stored);
        }
    }

    public function test_image_settings_tab_renders_preview_inputs(): void
    {
        SystemSetting::set('installed', '1');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);

        $this->actingAs($admin)->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('alt="Vorschau"', false);
    }

    private function settingsPayload(array $extra = []): array
    {
        return array_merge([
            'system_name' => 'Auth',
            'base_url' => 'https://auth.example.test',
            'timezone' => 'Europe/Berlin',
            'locale' => 'de',
            'session_lifetime' => 120,
        ], $extra);
    }

    public function test_logo_height_is_saved_and_shown_in_the_settings_form(): void
    {
        SystemSetting::set('installed', '1');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);

        $this->actingAs($admin)->put(route('admin.settings.update'), $this->settingsPayload([
            'logo_height_header' => 48,
            'logo_height_login' => 120,
        ]))->assertSessionHasNoErrors();

        $this->assertSame('48', SystemSetting::get('logo_height_header'));
        $this->assertSame('120', SystemSetting::get('logo_height_login'));

        $this->actingAs($admin)->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('name="logo_height_header"', false)
            ->assertSee('value="48"', false);
    }

    public function test_brand_mark_applies_the_configured_height_and_keeps_defaults(): void
    {
        $shared = ['systemName' => 'Kinzig', 'systemLogoUrl' => '/storage/branding/logo.svg', 'brandIcon' => ['mode' => 'default', 'shape' => 'rounded']];

        $login = view('components.brand-mark', ['context' => 'login', 'logoSize' => ['header' => 48, 'login' => 120]] + $shared)->render();
        $this->assertStringContainsString('height:120px', $login);
        $this->assertStringNotContainsString('max-h-14', $login);

        $header = view('components.brand-mark', ['context' => 'header', 'logoSize' => ['header' => 48, 'login' => 120]] + $shared)->render();
        $this->assertStringContainsString('height:48px', $header);

        $default = view('components.brand-mark', ['context' => 'login', 'logoSize' => ['header' => null, 'login' => null]] + $shared)->render();
        $this->assertStringContainsString('max-h-14', $default);
        $this->assertStringNotContainsString('height:', $default);
    }

    public function test_settings_overview_lists_open_tasks_and_status(): void
    {
        SystemSetting::set('installed', '1');
        SystemSetting::set('base_url', 'http://auth.example.test');
        SystemSetting::set('maintenance_mode', '1');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);

        $this->actingAs($admin)->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Zustand des Systems')
            ->assertSee('Basis-URL mit HTTPS')
            ->assertSee('Die Basis-URL beginnt nicht mit https://')
            ->assertSee('Nicht eingerichtet. Ohne E-Mail-Versand')
            ->assertSee('Aktiv. Normale Benutzer sehen nur die Wartungsseite.')
            ->assertSee('Ungespeicherte Änderungen');
    }

    public function test_validation_errors_open_the_section_that_contains_the_field(): void
    {
        SystemSetting::set('installed', '1');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);

        $this->actingAs($admin)->from(route('admin.settings.edit'))
            ->put(route('admin.settings.update'), $this->settingsPayload(['mail_port' => 'abc']));

        $this->actingAs($admin)->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee("return 'email';", false);
    }

    public function test_logo_height_must_be_within_limits(): void
    {
        SystemSetting::set('installed', '1');
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true, 'auth_source' => 'local']);

        $this->actingAs($admin)->put(route('admin.settings.update'), $this->settingsPayload(['logo_height_header' => 5]))
            ->assertSessionHasErrors('logo_height_header');
        $this->actingAs($admin)->put(route('admin.settings.update'), $this->settingsPayload(['logo_height_login' => 999]))
            ->assertSessionHasErrors('logo_height_login');
    }
}
