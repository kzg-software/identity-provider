<?php

namespace App\Workflow;

use App\Models\Application;
use App\Models\User;

/**
 * Alles, was Bausteine über den laufenden Vorgang wissen dürfen. Wird bei
 * einer Unterbrechung (z. B. Zwei-Faktor) serialisiert in der Session
 * abgelegt; deshalb nur Skalare/Arrays, keine Modelle.
 */
class WorkflowContext
{
    private ?User $userModel = null;

    private ?Application $applicationModel = null;

    private bool $applicationLoaded = false;

    /** vom Runner vor jedem Baustein gesetzt */
    public string $currentNode = '';

    /**
     * @param  string|null  $method  Erstmethode, z. B. local, active_directory, passkey, windows_sso (+ zweiter Faktor)
     * @param  string|null  $protocol  oidc oder saml (nur Authorize)
     * @param  array<string, mixed>  $flags  vom Ablauf gesetzte Merker, z. B. mfa_done, consent_given
     * @param  array<string, mixed>  $data  phasenspezifische Daten, z. B. scopes, client_id, audit_prefix
     */
    public function __construct(
        public readonly string $phase,
        public readonly ?int $userId,
        public readonly ?int $applicationId = null,
        public string $method = '',
        public readonly ?string $protocol = null,
        public array $flags = [],
        public array $data = [],
    ) {}

    public static function forLogin(User $user, string $method, ?Application $application): self
    {
        return new self('login', $user->getKey(), $application?->getKey(), $method);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function forAuthorize(User $user, ?Application $application, string $protocol, string $method, array $data = []): self
    {
        return new self('authorize', $user->getKey(), $application?->getKey(), $method, $protocol, [], $data);
    }

    public function user(): ?User
    {
        if ($this->userModel === null && $this->userId !== null) {
            $this->userModel = User::query()->find($this->userId);
        }

        return $this->userModel;
    }

    public function application(): ?Application
    {
        if (! $this->applicationLoaded) {
            $this->applicationModel = $this->applicationId ? Application::query()->find($this->applicationId) : null;
            $this->applicationLoaded = true;
        }

        return $this->applicationModel;
    }

    public function flag(string $key): bool
    {
        return (bool) ($this->flags[$key] ?? false);
    }

    public function setFlag(string $key, bool $value = true): void
    {
        $this->flags[$key] = $value;
    }

    /**
     * Prüf-Bausteine merken sich hier, warum sie blockiert haben, damit ein
     * nachfolgender „Ablehnen“-Baustein die passende Meldung zeigt.
     */
    public function setDenial(string $message, int $status): void
    {
        $this->data['denial'] = ['message' => $message, 'status' => $status];
    }

    /** @return array{message: string, status: int}|null */
    public function denial(): ?array
    {
        return $this->data['denial'] ?? null;
    }

    /**
     * Audit-Ereignis im Namensraum des Protokolls (oauth.authorize.* bzw. saml.sso.*).
     */
    public function auditEvent(string $suffix): string
    {
        return ($this->data['audit_prefix'] ?? $this->phase).'.'.$suffix;
    }

    /** @return array<string, mixed> */
    public function auditMeta(array $extra = []): array
    {
        return array_merge($this->data['audit_meta'] ?? [], $extra);
    }

    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'user_id' => $this->userId,
            'application_id' => $this->applicationId,
            'method' => $this->method,
            'protocol' => $this->protocol,
            'flags' => $this->flags,
            'data' => $this->data,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['phase'],
            $data['user_id'] ?? null,
            $data['application_id'] ?? null,
            $data['method'] ?? '',
            $data['protocol'] ?? null,
            $data['flags'] ?? [],
            $data['data'] ?? [],
        );
    }
}
