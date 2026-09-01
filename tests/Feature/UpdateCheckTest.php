<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\UpdateChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('installed', '1');

        config([
            'app.version' => 'v1.0.0',
            'updates.repository' => 'kzg-software/identity-provider',
            'updates.repository_url' => 'https://github.com/kzg-software/identity-provider',
            'updates.token' => null,
            'updates.ttl_hours' => 24,
        ]);
    }

    private function fakeRelease(string $tag, string $body = "## Änderungen\n- Etwas"): void
    {
        Http::fake([
            'api.github.com/repos/*/releases/latest' => Http::response([
                'tag_name' => $tag,
                'name' => $tag,
                'body' => $body,
                'html_url' => "https://github.com/kzg-software/identity-provider/releases/tag/{$tag}",
                'published_at' => '2026-09-01T10:00:00Z',
            ], 200),
        ]);
    }

    public function test_detects_a_newer_release(): void
    {
        $this->fakeRelease('v1.4.0');

        $status = UpdateChecker::refresh();

        $this->assertSame('v1.4.0', $status['latest']);
        $this->assertTrue($status['update_available']);
        $this->assertNull($status['error']);
    }

    public function test_up_to_date_when_latest_equals_current(): void
    {
        $this->fakeRelease('v1.0.0');

        $status = UpdateChecker::refresh();

        $this->assertSame('v1.0.0', $status['latest']);
        $this->assertFalse($status['update_available']);
    }

    public function test_api_failure_is_recorded_but_does_not_throw(): void
    {
        Http::fake(['api.github.com/*' => Http::response('nope', 500)]);

        $status = UpdateChecker::refresh();

        $this->assertNotNull($status['error']);
        $this->assertFalse($status['update_available']);
    }

    public function test_command_runs_and_records_result(): void
    {
        $this->fakeRelease('v2.0.0');

        $this->artisan('updates:check --force')->assertExitCode(0);

        $this->assertTrue(UpdateChecker::status()['update_available']);
    }

    public function test_admin_updates_page_shows_changelog_and_instructions(): void
    {
        $this->fakeRelease('v1.4.0', "## Highlights\n- Neuer Footer mit Versionsnummer");
        UpdateChecker::refresh();

        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);

        $response = $this->actingAs($admin)->get(route('admin.updates.index'));

        $response->assertOk();
        $response->assertSee('Aktualisierungen');
        $response->assertSee('v1.4.0');
        $response->assertSee('Neuer Footer mit Versionsnummer');
        $response->assertSee('So aktualisierst du');
        $response->assertSee('deploy/update.sh');
    }

    public function test_footer_shows_current_version_and_repo_link(): void
    {
        // Dashboard stößt eine Hintergrund-Aktualisierung an -> offline halten.
        Http::fake();

        $admin = User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('v1.0.0');
        $response->assertSee('Quellcode');
        $response->assertSee('github.com/kzg-software/identity-provider');
    }
}
