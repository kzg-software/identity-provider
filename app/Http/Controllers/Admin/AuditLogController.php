<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $query = $this->filtered($request);

        return view('admin.audit-log.index', [
            'logs' => $query->paginate(30)->withQueryString(),
            'events' => AuditLog::query()->select('event')->distinct()->orderBy('event')->pluck('event'),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'applications' => Application::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['user_id', 'event', 'application_id', 'from', 'to']),
        ]);
    }

    /**
     * GET /admin/audit-log/export?format=csv|json — die aktuell gefilterte
     * Ansicht als Datei herunterladen.
     */
    public function export(Request $request): StreamedResponse|Response
    {
        $request->validate(['format' => 'nullable|in:csv,json']);
        $format = $request->query('format', 'csv');

        $rows = $this->filtered($request)->orderBy('created_at')->lazy(500);
        $filename = 'audit-log-'.now()->format('Y-m-d-Hi').'.'.$format;

        if ($format === 'json') {
            return response()->streamDownload(function () use ($rows) {
                echo '[';
                $first = true;
                foreach ($rows as $log) {
                    echo $first ? '' : ',';
                    echo json_encode($this->row($log), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $first = false;
                }
                echo ']';
            }, $filename, ['Content-Type' => 'application/json']);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF"); // BOM, damit Excel UTF-8 erkennt
            fputcsv($out, ['Zeitpunkt', 'Ereignis', 'Benutzer', 'Benutzer-ID', 'IP', 'Anwendung', 'Anwendung-ID', 'User-Agent', 'Metadaten']);

            foreach ($rows as $log) {
                $r = $this->row($log);
                fputcsv($out, [
                    $r['timestamp'], $r['event'], $r['user'], $r['user_id'], $r['ip'],
                    $r['application'], $r['application_id'], $r['user_agent'],
                    $r['metadata'] === null ? '' : json_encode($r['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtered(Request $request): Builder
    {
        $query = AuditLog::query()->with(['user', 'application'])->latest('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('event')) {
            $query->where('event', 'like', '%'.$request->string('event').'%');
        }

        if ($request->filled('application_id')) {
            $query->where('application_id', $request->integer('application_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->date('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->date('to'));
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AuditLog $log): array
    {
        return [
            'timestamp' => $log->created_at instanceof Carbon ? $log->created_at->toIso8601String() : (string) $log->created_at,
            'event' => $log->event,
            'user' => $log->user?->name ?? ($log->metadata['username'] ?? null),
            'user_id' => $log->user_id,
            'ip' => $log->ip_address,
            'application' => $log->application?->name,
            'application_id' => $log->application_id,
            'user_agent' => $log->user_agent,
            'metadata' => $log->metadata ?: null,
        ];
    }
}
