<?php

namespace App\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\SessionTracker;
use App\Workflow\NodeResult;
use App\Workflow\WorkflowContext;
use App\Workflow\WorkflowResolver;
use App\Workflow\WorkflowRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

/**
 * Schließt eine Anmeldung ab. Zwischen bestandener Erstprüfung und dem
 * eigentlichen Login liegt der Login-Workflow (z. B. Zwei-Faktor).
 */
class LoginCompletion
{
    public function __construct(
        private SessionTracker $tracker,
        private WorkflowRunner $runner,
    ) {}

    /**
     * Einstieg nach erfolgreicher Erstprüfung (Passwort, Verzeichnis,
     * Passkey). Was danach passiert – Zwei-Faktor, Hinweise, Ablehnung –
     * entscheidet der Login-Workflow.
     */
    public function handle(Request $request, User $user, string $method): RedirectResponse
    {
        $request->session()->regenerate();

        $context = WorkflowContext::forLogin($user, $method, LoginTarget::application($request));

        return $this->conclude($request, $this->runner->start(WorkflowResolver::for('login', $context->application()), $context));
    }

    /**
     * Für die Windows-Anmeldung, die selbst anmeldet: nur den Login-Workflow
     * starten. Bei „Erlauben“ meldet der Aufrufer wie gewohnt an; bei
     * Ablehnen wird das protokolliert; eine Interaktion setzt conclude() um.
     */
    public function gate(Request $request, User $user, string $method): NodeResult
    {
        $context = WorkflowContext::forLogin($user, $method, LoginTarget::application($request));
        $result = $this->runner->start(WorkflowResolver::for('login', $context->application()), $context);

        if ($result->isDeny()) {
            AuditLog::record('login.failed', $user, ['method' => $method, 'reason' => 'workflow']);
        }

        return $result;
    }

    /** Wartet ein Login-Workflow auf eine Eingabe (z. B. Zwei-Faktor)? */
    public function hasPendingWorkflow(): bool
    {
        return $this->runner->pending('login') !== null;
    }

    /**
     * Rücksprung nach bestandenem zweiten Faktor: der geparkte Login-Workflow
     * läuft am Zwei-Faktor-Baustein weiter.
     */
    public function resumeAfterSecondFactor(Request $request, User $user, string $factor): RedirectResponse
    {
        $result = $this->runner->resume('login', function (WorkflowContext $context) use ($user, $factor) {
            if ($context->userId !== $user->getKey()) {
                throw ValidationException::withMessages(['username' => 'Die Anmeldung ist abgelaufen. Bitte erneut anmelden.']);
            }

            $context->setFlag('mfa_done');
            $context->method .= '+'.$factor;
        });

        if (! $result) {
            TwoFactorChallenge::forget();

            throw ValidationException::withMessages(['username' => 'Die Anmeldung ist abgelaufen. Bitte erneut anmelden.'])
                ->redirectTo(route('login'));
        }

        return $this->conclude($request, $result);
    }

    /**
     * Setzt das Ergebnis des Login-Workflows um.
     */
    public function conclude(Request $request, NodeResult $result): RedirectResponse
    {
        $context = $result->context;

        if ($result->isInteract()) {
            return match ($result->interaction) {
                'two_factor' => redirect()->route('two-factor.challenge'),
                default => redirect()->route('workflow.continue'),
            };
        }

        $user = $context?->user();

        if ($result->isAllow() && $user) {
            return $this->finalize($request, $user, $context->method);
        }

        TwoFactorChallenge::forget();

        if ($user) {
            AuditLog::record('login.failed', $user, ['method' => $context->method, 'reason' => 'workflow']);
        }

        throw ValidationException::withMessages(['username' => $result->message ?? 'Die Anmeldung ist nicht erlaubt.'])
            ->redirectTo(route('login'));
    }

    /**
     * Meldet den Nutzer endgültig an. Wird am Ende des Login-Workflows
     * („Erlauben“) aufgerufen.
     */
    public function finalize(Request $request, User $user, string $method): RedirectResponse
    {
        $request->session()->regenerate();

        Auth::guard('web')->login($user);

        // Anmeldeart dieser Sitzung, z. B. für „Mit zweitem Faktor angemeldet“.
        $request->session()->put('auth.method', $method);

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_method' => $method,
        ])->save();

        AuditLog::record('login.success', $user, ['method' => $method]);

        $this->tracker->record($user, $request, $method);

        Cookie::queue(Cookie::forget('auth_manual'));

        TwoFactorChallenge::forget();

        if ($request->session()->has('saml.pending')) {
            return redirect()->route('saml.sso.resume');
        }

        // Administratoren landen in der Systemverwaltung, alle anderen im Portal.
        $home = $user->is_admin ? route('admin.dashboard') : route('dashboard');

        return redirect()->intended($home);
    }

    /** Bei fetch()-Anfragen (Passkey) das Ziel als JSON statt als Weiterleitung. */
    public function respond(Request $request, RedirectResponse $redirect): RedirectResponse|JsonResponse
    {
        if (! $request->expectsJson()) {
            return $redirect;
        }

        return response()->json(['redirect' => $redirect->getTargetUrl()]);
    }
}
