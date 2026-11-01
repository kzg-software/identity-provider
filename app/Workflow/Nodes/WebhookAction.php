<?php

namespace App\Workflow\Nodes;

use App\Models\AuditLog;
use App\Workflow\WorkflowContext;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Schickt das Ereignis als JSON an eine URL. Rein ausgehend: die Antwort hat
 * keinen Einfluss auf den Ablauf. Mit Geheimnis wird der Body per HMAC-SHA256
 * signiert (Header X-Signature: sha256=…).
 */
class WebhookAction extends ActionNode
{
    public function key(): string
    {
        return 'action.webhook';
    }

    public function label(): string
    {
        return 'Webhook senden';
    }

    public function description(): string
    {
        return 'Sendet das Ereignis als JSON per POST an eine URL, z. B. an ein Ticketsystem oder einen Chat.';
    }

    public function fields(): array
    {
        return [
            ['key' => 'url', 'label' => 'URL', 'type' => 'text', 'default' => '', 'required' => true],
            ['key' => 'secret', 'label' => 'Geheimnis für die Signatur', 'type' => 'text', 'default' => '', 'help' => 'Optional. Der Empfänger kann den Header X-Signature damit prüfen.'],
        ];
    }

    public function validate(array $config): array
    {
        $errors = parent::validate($config);
        $url = (string) ($config['url'] ?? '');

        if ($url !== '' && (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
            $errors[] = 'Die URL muss mit http:// oder https:// beginnen.';
        }

        return $errors;
    }

    protected function perform(array $config, WorkflowContext $context): void
    {
        $user = $context->user();
        $application = $context->application();

        $body = json_encode([
            'event' => $context->data['event'] ?? null,
            'occurred_at' => $context->data['occurred_at'] ?? now()->toIso8601String(),
            'user' => $user ? ['id' => $user->id, 'username' => $user->username, 'name' => $user->name, 'email' => $user->email] : null,
            'application' => $application ? ['id' => $application->id, 'name' => $application->name] : null,
            'ip' => $context->data['ip'] ?? null,
            'metadata' => $context->data['metadata'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = ['Content-Type' => 'application/json'];

        if (filled($config['secret'])) {
            $headers['X-Signature'] = 'sha256='.hash_hmac('sha256', $body, (string) $config['secret']);
        }

        try {
            $response = Http::withHeaders($headers)->timeout(5)->withBody($body, 'application/json')->post($config['url']);

            if ($response->failed()) {
                throw new \RuntimeException('HTTP '.$response->status());
            }

            $this->audit($context, ['url' => $config['url']]);
        } catch (Throwable $e) {
            AuditLog::record('workflow.webhook_failed', $user, ['url' => $config['url'], 'error' => $e->getMessage()], $application);
        }
    }
}
