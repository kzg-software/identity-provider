<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MailQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', '1');
    }

    private function admin(): User
    {
        return User::factory()->create(['auth_source' => 'local', 'is_admin' => true, 'is_active' => true]);
    }

    public function test_page_lists_failed_jobs_and_pending_count(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'mail', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'abc-123',
            'connection' => 'database',
            'queue' => 'mail',
            'payload' => json_encode(['displayName' => 'Illuminate\\Mail\\SendQueuedMailable']),
            'exception' => "Connection could not be established\nStack trace ...",
            'failed_at' => now(),
        ]);

        $this->actingAs($this->admin())->get(route('admin.mail-queue.index'))
            ->assertOk()
            ->assertSee('Fehlgeschlagene E-Mails')
            ->assertSee('Connection could not be established')
            ->assertSee('1'); // pending count
    }

    public function test_pending_mail_can_be_cancelled(): void
    {
        $id = DB::table('jobs')->insertGetId([
            'queue' => 'mail', 'payload' => '{"displayName":"App\\\\Mail\\\\SystemMail"}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);

        $this->actingAs($this->admin())->get(route('admin.mail-queue.index'))
            ->assertOk()->assertSee('Wartende E-Mails')->assertSee('Systemnachricht');

        $this->actingAs($this->admin())->delete(route('admin.mail-queue.cancel', $id))->assertRedirect();
        $this->assertDatabaseMissing('jobs', ['id' => $id]);
    }

    public function test_in_progress_mail_cannot_be_cancelled(): void
    {
        $id = DB::table('jobs')->insertGetId([
            'queue' => 'mail', 'payload' => '{}', 'attempts' => 1,
            'reserved_at' => now()->timestamp, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);

        $this->actingAs($this->admin())->delete(route('admin.mail-queue.cancel', $id))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('jobs', ['id' => $id]);
    }

    public function test_forget_removes_a_failed_job(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'to-forget',
            'connection' => 'database', 'queue' => 'mail',
            'payload' => '{}', 'exception' => 'x', 'failed_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.mail-queue.forget', 'to-forget'))
            ->assertRedirect();

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'to-forget']);
    }

    public function test_flush_clears_all_failed_jobs(): void
    {
        DB::table('failed_jobs')->insert([
            ['uuid' => 'a', 'connection' => 'database', 'queue' => 'mail', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()],
            ['uuid' => 'b', 'connection' => 'database', 'queue' => 'mail', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()],
        ]);

        $this->actingAs($this->admin())->delete(route('admin.mail-queue.flush'))->assertRedirect();

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_non_admin_has_no_access(): void
    {
        $user = User::factory()->create(['auth_source' => 'local', 'is_active' => true]);

        $this->actingAs($user)->get(route('admin.mail-queue.index'))->assertForbidden();
    }
}
