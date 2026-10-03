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
}
