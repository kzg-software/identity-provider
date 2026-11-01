<?php

namespace App\Workflow;

/**
 * Ein Bausteintyp für den Workflow-Editor. Konkrete Bausteine liegen unter
 * App\Workflow\Nodes und werden in der NodeRegistry eingetragen.
 */
abstract class NodeType
{
    public const CATEGORY_FLOW = 'Ablauf';

    public const CATEGORY_CONDITION = 'Bedingung';

    public const CATEGORY_CHECK = 'Prüfung';

    public const CATEGORY_INTERACTION = 'Interaktion';

    public const CATEGORY_ACTION = 'Aktion';

    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function category(): string;

    public function description(): string
    {
        return '';
    }

    /** @return list<string> Phasen, in denen der Baustein verwendet werden darf */
    public function phases(): array
    {
        return ['login', 'authorize'];
    }

    /**
     * Konfigurationsfelder fürs Eigenschaften-Panel.
     * Feldtypen: text, textarea, lines, number, checkbox, select, multiselect.
     *
     * @return list<array{key: string, label: string, type: string, default?: mixed, options?: array<string, string>, help?: string, required?: bool}>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Benannte Ausgänge (Schlüssel => Beschriftung). Leer = Endpunkt.
     *
     * @return array<string, string>
     */
    public function outputs(array $config): array
    {
        return ['weiter' => 'weiter'];
    }

    public function isTerminal(): bool
    {
        return false;
    }

    /** Darf höchstens einmal vorkommen und hat keinen Eingang (Start/Trigger). */
    public function isEntry(): bool
    {
        return false;
    }

    abstract public function run(array $config, WorkflowContext $context): NodeResult;

    /**
     * Prüft die Konfiguration. Standard: Pflichtfelder gesetzt.
     *
     * @return list<string> Fehlermeldungen
     */
    public function validate(array $config): array
    {
        $errors = [];

        foreach ($this->fields() as $field) {
            if (($field['required'] ?? false) && blank($config[$field['key']] ?? null)) {
                $errors[] = "„{$field['label']}“ muss ausgefüllt sein.";
            }
        }

        return $errors;
    }

    /** Konfiguration mit Standardwerten der Felder aufgefüllt. */
    public function withDefaults(array $config): array
    {
        foreach ($this->fields() as $field) {
            if (! array_key_exists($field['key'], $config) && array_key_exists('default', $field)) {
                $config[$field['key']] = $field['default'];
            }
        }

        return $config;
    }

    /** Zeilen/Komma-getrennte Liste aus einem Textfeld. */
    protected static function lines(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value), 'strlen'));
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string) $value)), 'strlen'));
    }

    /** @return array<string, mixed> Beschreibung für den Editor */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label(),
            'category' => $this->category(),
            'description' => $this->description(),
            'phases' => $this->phases(),
            'fields' => $this->fields(),
            'outputs' => $this->outputs($this->withDefaults([])),
            'terminal' => $this->isTerminal(),
            'entry' => $this->isEntry(),
        ];
    }
}
