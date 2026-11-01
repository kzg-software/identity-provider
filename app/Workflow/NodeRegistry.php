<?php

namespace App\Workflow;

use App\Workflow\Nodes;

/**
 * Alle verfügbaren Bausteintypen. Neue Bausteine hier eintragen.
 */
class NodeRegistry
{
    /** @var list<class-string<NodeType>> */
    private const TYPES = [
        Nodes\StartNode::class,
        Nodes\AllowNode::class,
        Nodes\DenyNode::class,
        Nodes\GroupCondition::class,
        Nodes\RoleCondition::class,
        Nodes\AdminCondition::class,
        Nodes\AuthSourceCondition::class,
        Nodes\MethodCondition::class,
        Nodes\ApplicationCondition::class,
        Nodes\IpRangeCondition::class,
        Nodes\TimeWindowCondition::class,
        Nodes\TwoFactorEnabledCondition::class,
        Nodes\TrustedDeviceCondition::class,
        Nodes\NewDeviceCondition::class,
        Nodes\SecondFactorUsedCondition::class,
        Nodes\TwoFactorNode::class,
        Nodes\NoticeNode::class,
        Nodes\TermsNode::class,
        Nodes\MaintenanceCheck::class,
        Nodes\RestrictionCheck::class,
        Nodes\PolicyCheck::class,
        Nodes\ConsentNode::class,
    ];

    /** @var array<string, NodeType>|null */
    private ?array $types = null;

    /** @return array<string, NodeType> */
    public function all(): array
    {
        if ($this->types === null) {
            $this->types = [];

            foreach (self::TYPES as $class) {
                $type = app($class);
                $this->types[$type->key()] = $type;
            }
        }

        return $this->types;
    }

    public function get(string $key): ?NodeType
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, NodeType> */
    public function forPhase(string $phase): array
    {
        return array_filter($this->all(), fn (NodeType $type) => in_array($phase, $type->phases(), true));
    }
}
