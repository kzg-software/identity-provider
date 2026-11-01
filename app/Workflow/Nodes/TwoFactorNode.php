<?php

namespace App\Workflow\Nodes;

use App\Auth\TrustedDevices;
use App\Auth\TwoFactorChallenge;
use App\Models\AuditLog;
use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;

/**
 * Verlangt den zweiten Faktor über die bestehende Zwei-Faktor-Seite. Nach
 * bestandener Prüfung springt der TwoFactorChallengeController in den
 * Workflow zurück (Merker mfa_done) und es geht über „bestätigt“ weiter.
 */
class TwoFactorNode extends NodeType
{
    public function key(): string
    {
        return 'two_factor';
    }

    public function label(): string
    {
        return 'Zwei-Faktor verlangen';
    }

    public function category(): string
    {
        return self::CATEGORY_INTERACTION;
    }

    public function description(): string
    {
        return 'Fragt den zweiten Faktor (Passkey, Authenticator-App oder Wiederherstellungscode) ab. Konten ohne zweiten Faktor gehen über „nicht eingerichtet“ weiter.';
    }

    public function phases(): array
    {
        return ['login'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'skip_trusted', 'label' => 'Vertraute Geräte nicht erneut fragen', 'type' => 'checkbox', 'default' => true],
            ['key' => 'passkey_counts', 'label' => 'Passwortlose Passkey-Anmeldung zählt bereits als zwei Faktoren', 'type' => 'checkbox', 'default' => true],
            ['key' => 'windows_counts', 'label' => 'Windows-Anmeldung (SSO) zählt bereits als zwei Faktoren', 'type' => 'checkbox', 'default' => true],
        ];
    }

    public function outputs(array $config): array
    {
        return ['bestaetigt' => 'bestätigt', 'nicht_eingerichtet' => 'nicht eingerichtet'];
    }

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        $config = $this->withDefaults($config);
        $user = $context->user();

        if ($context->flag('mfa_done')) {
            return NodeResult::next('bestaetigt');
        }

        $primary = MethodCondition::primary($context->method);

        if (($config['passkey_counts'] && $primary === 'passkey') || ($config['windows_counts'] && $primary === 'windows_sso')) {
            return NodeResult::next('bestaetigt');
        }

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return NodeResult::next('nicht_eingerichtet');
        }

        if ($context->dryRun) {
            return NodeResult::interact('two_factor');
        }

        if ($config['skip_trusted'] && app(TrustedDevices::class)->isTrusted($user, request())) {
            AuditLog::record('login.2fa_skipped_trusted_device', $user, ['method' => $context->method]);

            return NodeResult::next('bestaetigt');
        }

        TwoFactorChallenge::start($user, $context->method);
        AuditLog::record('login.2fa_required', $user, ['method' => $context->method]);

        return NodeResult::interact('two_factor');
    }
}
