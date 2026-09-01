<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
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

        return view('admin.audit-log.index', [
            'logs' => $query->paginate(30)->withQueryString(),
            'events' => AuditLog::query()->select('event')->distinct()->orderBy('event')->pluck('event'),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'applications' => Application::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['user_id', 'event', 'application_id', 'from', 'to']),
        ]);
    }
}
