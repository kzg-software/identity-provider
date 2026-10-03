<?php

namespace App\Scim;

use App\Models\ScimConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Minimaler SCIM-2.0-Client (RFC 7643/7644) für die Provisionierung von
 * Benutzern und Gruppen in Zielanwendungen.
 */
class ScimClient
{
    public function __construct(private readonly ScimConnection $connection) {}

    public function serviceProviderConfig(): array
    {
        return $this->send('get', '/ServiceProviderConfig')->json() ?? [];
    }

    /**
     * @return array{id: string}
     */
    public function create(string $collection, array $payload): array
    {
        $response = $this->send('post', "/{$collection}", $payload, allowStatus: [409]);

        if ($response->status() === 409) {
            $existing = $this->findByKey($collection, $payload);

            if ($existing === null) {
                throw new ScimException("{$collection}: Eintrag existiert bereits, ist aber nicht auffindbar.", 409);
            }

            $this->replace($collection, $existing, $payload);

            return ['id' => $existing];
        }

        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw new ScimException("{$collection}: Antwort enthält keine ID.");
        }

        return ['id' => $id];
    }

    public function replace(string $collection, string $remoteId, array $payload): void
    {
        $this->send('put', "/{$collection}/".rawurlencode($remoteId), $payload);
    }

    public function setActive(string $remoteId, bool $active): void
    {
        $this->send('patch', '/Users/'.rawurlencode($remoteId), [
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [['op' => 'replace', 'path' => 'active', 'value' => $active]],
        ]);
    }

    public function delete(string $collection, string $remoteId): void
    {
        $this->send('delete', "/{$collection}/".rawurlencode($remoteId), allowStatus: [404]);
    }

    private function findByKey(string $collection, array $payload): ?string
    {
        [$attribute, $value] = $collection === 'Users'
            ? ['userName', $payload['userName'] ?? '']
            : ['displayName', $payload['displayName'] ?? ''];

        $filter = $attribute.' eq "'.addcslashes((string) $value, '\\"').'"';
        $resources = $this->send('get', "/{$collection}", query: ['filter' => $filter])->json('Resources') ?? [];

        return $resources[0]['id'] ?? null;
    }

    private function send(string $method, string $path, ?array $body = null, array $query = [], array $allowStatus = []): Response
    {
        $url = rtrim($this->connection->base_url, '/').$path.($query ? '?'.http_build_query($query) : '');

        try {
            $request = $this->request();

            if ($body !== null) {
                $request = $request->withBody(json_encode($body, JSON_UNESCAPED_UNICODE), 'application/scim+json');
            }

            $response = $request->send(strtoupper($method), $url);
        } catch (\Throwable $e) {
            throw new ScimException('Verbindung fehlgeschlagen: '.$e->getMessage());
        }

        if ($response->successful() || in_array($response->status(), $allowStatus, true)) {
            return $response;
        }

        $detail = $response->json('detail') ?? mb_substr(trim(strip_tags($response->body())), 0, 200);

        throw new ScimException("HTTP {$response->status()} bei {$method} {$path}".($detail ? ": {$detail}" : ''), $response->status());
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->connection->auth_token)
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5);
    }
}
