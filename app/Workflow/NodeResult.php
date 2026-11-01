<?php

namespace App\Workflow;

/**
 * Ergebnis eines Bausteins: weiter über einen Ausgang, pausieren für eine
 * Nutzerinteraktion, oder den Ablauf mit Erlauben/Ablehnen beenden.
 */
final class NodeResult
{
    public const NEXT = 'next';

    public const INTERACT = 'interact';

    public const ALLOW = 'allow';

    public const DENY = 'deny';

    /** @var list<string> vom Runner gefüllt: durchlaufene Knoten-IDs */
    public array $path = [];

    /** vom Runner gesetzt: Kontext am Ende des Laufs */
    public ?WorkflowContext $context = null;

    private function __construct(
        public readonly string $kind,
        public readonly ?string $output = null,
        public readonly ?string $interaction = null,
        public readonly ?string $message = null,
        public readonly int $status = 403,
    ) {}

    public static function next(string $output = 'weiter'): self
    {
        return new self(self::NEXT, output: $output);
    }

    public static function interact(string $interaction): self
    {
        return new self(self::INTERACT, interaction: $interaction);
    }

    public static function allow(): self
    {
        return new self(self::ALLOW);
    }

    public static function deny(string $message, int $status = 403): self
    {
        return new self(self::DENY, message: $message, status: $status);
    }

    public function isAllow(): bool
    {
        return $this->kind === self::ALLOW;
    }

    public function isDeny(): bool
    {
        return $this->kind === self::DENY;
    }

    public function isInteract(): bool
    {
        return $this->kind === self::INTERACT;
    }
}
