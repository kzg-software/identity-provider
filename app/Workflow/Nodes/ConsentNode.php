<?php

namespace App\Workflow\Nodes;

use App\Models\OauthConsent;
use App\Workflow\NodeResult;
use App\Workflow\NodeType;
use App\Workflow\WorkflowContext;

/**
 * Einwilligungsabfrage für OIDC-Anwendungen gemäß deren Einstellung
 * (consent_required / consent_mode). Bei SAML gibt es keine Einwilligung,
 * der Baustein lässt dann einfach durch.
 */
class ConsentNode extends NodeType
{
    public function key(): string
    {
        return 'consent';
    }

    public function label(): string
    {
        return 'Einwilligung einholen';
    }

    public function category(): string
    {
        return self::CATEGORY_INTERACTION;
    }

    public function description(): string
    {
        return 'Zeigt bei OIDC-Anwendungen die Einwilligungsseite, je nach Einstellung der Anwendung (einmalig, bei neuen Berechtigungen, immer oder nie).';
    }

    public function phases(): array
    {
        return ['authorize'];
    }

    public function run(array $config, WorkflowContext $context): NodeResult
    {
        if ($context->protocol !== 'oidc' || $context->flag('consent_given') || $this->satisfied($context)) {
            return NodeResult::next();
        }

        return NodeResult::interact('oidc_consent');
    }

    private function satisfied(WorkflowContext $context): bool
    {
        $application = $context->application();

        if (! $application || ! $application->consent_required || $application->consent_mode === 'skip') {
            return true;
        }

        $consent = OauthConsent::query()
            ->where('user_id', $context->userId)
            ->where('oauth_client_id', $context->data['client_id'] ?? null)
            ->whereNull('revoked_at')
            ->first();

        if (! $consent) {
            return false;
        }

        if ($application->consent_mode === 'always') {
            return false;
        }

        if ($application->consent_mode === 'on_scope_change') {
            return empty(array_diff($context->data['scopes'] ?? [], $consent->scopes ?? []));
        }

        // consent_mode === 'first_time' (Standard): einmal erteilt, nie wieder fragen.
        return true;
    }
}
