<?php

namespace App\Workflow\Nodes;

use App\Support\AccessRestrictions;
use App\Workflow\WorkflowContext;

class IpRangeCondition extends ConditionNode
{
    public function key(): string
    {
        return 'condition.ip_range';
    }

    public function label(): string
    {
        return 'IP-Adresse in Bereich';
    }

    public function description(): string
    {
        return 'Ja, wenn die Anfrage aus einem der Netze kommt.';
    }

    public function phases(): array
    {
        return ['login', 'authorize', 'event'];
    }

    public function fields(): array
    {
        return [
            ['key' => 'ranges', 'label' => 'Netze', 'type' => 'lines', 'default' => '', 'required' => true, 'help' => 'IP-Adressen oder CIDR-Bereiche, z. B. 10.0.0.0/8, eine pro Zeile.'],
        ];
    }

    protected function matches(array $config, WorkflowContext $context): bool
    {
        return AccessRestrictions::ipAllowed($context->data['ip'] ?? request()->ip(), implode("\n", self::lines($config['ranges'] ?? '')));
    }

    public function validate(array $config): array
    {
        $errors = parent::validate($config);

        foreach (self::lines($config['ranges'] ?? '') as $entry) {
            if (! AccessRestrictions::isValidIpRange($entry)) {
                $errors[] = "„{$entry}“ ist keine gültige IP-Adresse bzw. kein gültiger CIDR-Bereich.";
            }
        }

        return $errors;
    }
}
