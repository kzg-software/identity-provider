<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\User;
use App\Services\AccessPolicyEvaluator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Persönliches Portal: die Anwendungen, auf die der angemeldete Benutzer
     * Zugriff hat. Für alle Benutzer gleich, auch für Administratoren - die
     * Systemverwaltung liegt getrennt unter /admin.
     */
    public function index(): View
    {
        $user = Auth::user();
        $applications = $this->applicationsAccessibleBy($user);

        return view('dashboard-user', [
            'applications' => $applications,
            'categorizedApplications' => $applications->filter(fn (Application $a) => filled($a->category))
                ->groupBy('category')
                ->sortKeys(),
            'uncategorizedApplications' => $applications->filter(fn (Application $a) => blank($a->category))->values(),
        ]);
    }

    /**
     * @return Collection<int, Application>
     */
    private function applicationsAccessibleBy(User $user)
    {
        return Application::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Application $application) => AccessPolicyEvaluator::mayAccess($application, $user))
            ->values();
    }
}
