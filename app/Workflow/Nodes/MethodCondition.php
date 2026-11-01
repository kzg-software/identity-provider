<?php

namespace App\Workflow\Nodes;

use App\Workflow\WorkflowContext;

class MethodCondition extends ConditionNode
{
    public const METHODS = [
        'local' => 'Passwort (lokales Konto)',
        'active_directory' => 'Passwort (Active Directory)',
        'passkey' => 'Passkey (passwortlos)',
        'windows_sso' => 'Windows-Anmeldung (SSO)',
    ];

    public function key(): string
    {
        return 'condition.method';
    }

    public function label(): string
    {
        return 'Angemeldet mit';
    }

    public function description(): string
    {
        return 'Ja, wenn die erste Anmeldung über eine der Methoden erfolgt ist.';
    }

    public function fields(): array
    {
        return [
            ['key' => 'methods', 'label' => 'Methoden', 'type' => 'multiselect', 'default' => [], 'required' => true, 'options' => self::METHODS],
        ];
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        $primary = self::primary($context->method);

        return in_array($primary, (array) ($config['methods'] ?? []), true);
    }

    /** „local+totp“ → „local“; „windows_sso“ für beide Windows-Wege. */
    public static function primary(string $method): string
    {
        $primary = explode('+', $method)[0];

        return str_starts_with($primary, 'windows_sso') ? 'windows_sso' : $primary;
    }
}
