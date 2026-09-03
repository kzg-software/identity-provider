<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\AuditForwarder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        SystemSetting::set('installed', '1');

        return User::factory()->create([
            'auth_source' => 'local',
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    private function olderEntry(string $event, int $daysAgo): AuditLog
    {
        $log = AuditLog::record($event);
        $log->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();

        return $log;
    }

    public function test_export_as_csv_returns_the_filtered_rows(): void
    {
        $admin = $this->admin();
        AuditLog::record('login.success');
        AuditLog::record('oauth.client_created');

        $response = $this->actingAs($admin)->get(route('admin.audit-log.export', ['format' => 'csv']));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = $response->streamedContent();
        $this->assertStringContainsString('Zeitpunkt,Ereignis', $body);
        $this->assertStringContainsString('login.success', $body);
        $this->assertStringContainsString('oauth.client_created', $body);
    }

    public function test_export_as_json_is_a_valid_array(): void
    {
        $admin = $this->admin();
        AuditLog::record('login.success');
        AuditLog::record('login.failed');

        $body = $this->actingAs($admin)
            ->get(route('admin.audit-log.export', ['format' => 'json']))
            ->assertOk()
            ->streamedContent();

        $decoded = json_decode($body, true);

        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame('login.success', $decoded[0]['event']);
    }

    public function test_export_respects_the_event_filter(): void
    {
        $admin = $this->admin();
        AuditLog::record('login.success');
        AuditLog::record('oauth.client_created');

        $body = $this->actingAs($admin)
            ->get(route('admin.audit-log.export', ['format' => 'json', 'event' => 'oauth']))
            ->streamedContent();

        $decoded = json_decode($body, true);
        $this->assertCount(1, $decoded);
        $this->assertSame('oauth.client_created', $decoded[0]['event']);
    }

    public function test_prune_deletes_entries_older_than_the_retention(): void
    {
        SystemSetting::set('audit_log_retention_days', '30');

        $this->olderEntry('old.event', 60);
        $fresh = AuditLog::record('fresh.event');

        $this->artisan('audit-log:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['event' => 'old.event']);
        $this->assertDatabaseHas('audit_logs', ['id' => $fresh->id]);
    }

    public function test_prune_keeps_everything_without_a_retention(): void
    {
        SystemSetting::set('audit_log_retention_days', '0');
        $this->olderEntry('ancient.event', 900);

        $this->artisan('audit-log:prune')->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', ['event' => 'ancient.event']);
    }

    public function test_forwarding_is_off_by_default(): void
    {
        $this->assertFalse(AuditForwarder::enabled());

        // Muss ohne Ausnahme durchlaufen.
        $log = AuditLog::record('login.success');
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_a_failing_forward_target_does_not_break_the_audited_action(): void
    {
        SystemSetting::set('audit_forward_enabled', '1');
        SystemSetting::set('audit_forward_host', '127.0.0.1'); // nichts lauscht -> Verbindung abgelehnt
        SystemSetting::set('audit_forward_port', '1');
        SystemSetting::set('audit_forward_protocol', 'tcp');

        $log = AuditLog::record('login.success');

        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }
}
