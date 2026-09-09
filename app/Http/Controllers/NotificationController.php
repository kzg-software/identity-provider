<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => $request->user()->notifications()->paginate(25),
            'unreadCount' => $request->user()->notifications()->unread()->count(),
        ]);
    }

    /**
     * Öffnet die Benachrichtigung: markiert sie als gelesen und leitet zum
     * hinterlegten Ziel weiter (oder zurück zur Übersicht).
     */
    public function open(Request $request, Notification $notification): RedirectResponse
    {
        $this->authorizeOwner($request, $notification);

        $notification->markRead();

        return redirect($notification->action_url ?: route('notifications.index'));
    }

    public function markRead(Request $request, Notification $notification): RedirectResponse
    {
        $this->authorizeOwner($request, $notification);

        $notification->markRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->notifications()->unread()->update(['read_at' => now()]);

        return back()->with('status', 'Alle Benachrichtigungen als gelesen markiert.');
    }

    public function destroy(Request $request, Notification $notification): RedirectResponse
    {
        $this->authorizeOwner($request, $notification);

        $notification->delete();

        return back();
    }

    public function clear(Request $request): RedirectResponse
    {
        $request->user()->notifications()->delete();

        return back()->with('status', 'Benachrichtigungen geleert.');
    }

    private function authorizeOwner(Request $request, Notification $notification): void
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
    }
}
