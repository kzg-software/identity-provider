<?php

namespace App\Services;

use App\Models\AccessPolicy;
use App\Models\Application;
use App\Models\User;

/**
 * Deny-overrides-allow access policy evaluation (promt.md Abschnitt 25),
 * shared by the OIDC authorization endpoint, the SAML SSO endpoint, and the
 * "my applications" dashboard tile — so the access decision a user sees on
 * their dashboard always matches the decision actually enforced at login.
 */
class AccessPolicyEvaluator
{
    public static function mayAccessApplication(?int $applicationId, User $user): bool
    {
        if (! $applicationId) {
            return true;
        }

        $policies = AccessPolicy::query()->where('application_id', $applicationId)->orderByDesc('priority')->get();

        if ($policies->isEmpty()) {
            return true;
        }

        $groups = $user->groupNames();
        $denies = [];
        $allows = [];

        foreach ($policies as $policy) {
            $matches = match ($policy->subject_type) {
                'user' => strcasecmp($policy->subject_value, $user->username ?? '') === 0 || strcasecmp($policy->subject_value, $user->upn ?? '') === 0,
                'group' => in_array($policy->subject_value, $groups, true),
                'domain' => strcasecmp($policy->subject_value, $user->domain ?? '') === 0,
                default => false,
            };

            if (! $matches) {
                continue;
            }

            if ($policy->effect === 'deny') {
                $denies[] = $policy;
            } else {
                $allows[] = $policy;
            }
        }

        if (! empty($denies)) {
            return false;
        }

        return ! empty($allows);
    }

    public static function mayAccess(Application $application, User $user): bool
    {
        return self::mayAccessApplication($application->id, $user);
    }
}
