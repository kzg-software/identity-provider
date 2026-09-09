<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MailQueueController extends Controller
{
    private const MAIL_QUEUES = ['mail', 'default'];

    public function index(): View
    {
        $pendingRows = DB::table('jobs')
            ->whereIn('queue', self::MAIL_QUEUES)
            ->orderBy('id')
            ->limit(200)
            ->get();

        $pending = $pendingRows->map(fn ($row) => [
            'id' => $row->id,
            'queue' => $row->queue,
            'job' => $this->jobName($row->payload),
            'recipient' => $this->recipient($row->payload),
            'queued_at' => Carbon::createFromTimestamp($row->created_at),
            'attempts' => (int) $row->attempts,
            'in_progress' => $row->reserved_at !== null,
        ]);

        $failed = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'uuid' => $row->uuid,
                'queue' => $row->queue,
                'failed_at' => Carbon::parse($row->failed_at),
                'job' => $this->jobName($row->payload),
                'recipient' => $this->recipient($row->payload),
                'error' => Str::of($row->exception)->before("\n")->limit(240)->toString(),
            ]);

        return view('admin.mail-queue.index', [
            'mailConfigured' => MailSettings::configured(),
            'pending' => $pending,
            'pendingTotal' => DB::table('jobs')->whereIn('queue', self::MAIL_QUEUES)->count(),
            'failed' => $failed,
            'failedTotal' => DB::table('failed_jobs')->count(),
            'schedulerHeartbeat' => Cache::get('schedule.heartbeat'),
        ]);
    }

    public function cancel(Request $request, int $job): RedirectResponse
    {
        $deleted = DB::table('jobs')
            ->where('id', $job)
            ->whereIn('queue', self::MAIL_QUEUES)
            ->whereNull('reserved_at')
            ->delete();

        if ($deleted) {
            AuditLog::record('admin.mail_queue_cancelled', $request->user(), ['job_id' => $job]);

            return back()->with('status', 'E-Mail wurde abgebrochen.');
        }

        return back()->with('error', 'Die E-Mail wird bereits gesendet und kann nicht mehr abgebrochen werden.');
    }

    public function cancelAll(Request $request): RedirectResponse
    {
        $deleted = DB::table('jobs')
            ->whereIn('queue', self::MAIL_QUEUES)
            ->whereNull('reserved_at')
            ->delete();

        AuditLog::record('admin.mail_queue_cancelled', $request->user(), ['scope' => 'all', 'count' => $deleted]);

        return back()->with('status', $deleted.' wartende E-Mails wurden abgebrochen.');
    }

    public function process(Request $request): RedirectResponse
    {
        Artisan::call('queue:work', [
            '--queue' => implode(',', self::MAIL_QUEUES),
            '--stop-when-empty' => true,
            '--max-time' => 15,
            '--tries' => 3,
        ]);

        return back()->with('status', 'Warteschlange wurde abgearbeitet.');
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        AuditLog::record('admin.mail_queue_retry', $request->user(), ['uuid' => $uuid]);

        return back()->with('status', 'E-Mail wird erneut zugestellt.');
    }

    public function retryAll(Request $request): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => ['all']]);
        AuditLog::record('admin.mail_queue_retry', $request->user(), ['scope' => 'all']);

        return back()->with('status', 'Alle fehlgeschlagenen E-Mails werden erneut zugestellt.');
    }

    public function forget(Request $request, string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return back()->with('status', 'Eintrag wurde verworfen.');
    }

    public function flush(Request $request): RedirectResponse
    {
        Artisan::call('queue:flush');
        AuditLog::record('admin.mail_queue_flush', $request->user());

        return back()->with('status', 'Alle fehlgeschlagenen Einträge wurden verworfen.');
    }

    private function jobName(string $payload): string
    {
        $data = json_decode($payload, true);
        $name = $data['displayName'] ?? ($data['job'] ?? 'Job');

        return match (true) {
            str_contains($name, 'SystemMail') => 'Systemnachricht',
            str_contains($name, 'QueuedMailable'), str_contains($name, 'Mail') => 'E-Mail',
            default => class_basename($name),
        };
    }

    /**
     * Versucht, die Empfaengeradresse aus dem serialisierten Mail-Job zu lesen.
     * Schlaegt das fehl, bleibt das Feld leer.
     */
    private function recipient(string $payload): ?string
    {
        try {
            $data = json_decode($payload, true);
            $command = $data['data']['command'] ?? null;

            if (! is_string($command) || ! str_contains($command, 'Mailable')) {
                return null;
            }

            $object = unserialize($command, ['allowed_classes' => true]);
            $mailable = $object->mailable ?? null;
            $to = $mailable->to ?? [];

            return collect($to)->pluck('address')->filter()->implode(', ') ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
