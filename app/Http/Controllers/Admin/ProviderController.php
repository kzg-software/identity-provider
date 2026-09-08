<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\OauthScope;
use App\Models\Provider;
use App\Models\SamlAttributeMapping;
use App\Services\ProviderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProviderController extends Controller
{
    public function __construct(private readonly ProviderService $service) {}

    public function index(): View
    {
        $providers = Provider::query()
            ->with(['oauthClient.redirectUris', 'samlServiceProvider', 'applications'])
            ->orderBy('name')
            ->get();

        return view('admin.providers.index', compact('providers'));
    }

    public function create(Request $request): View
    {
        $type = $request->query('type');

        return view('admin.providers.create', [
            'type' => in_array($type, [Provider::TYPE_OIDC, Provider::TYPE_SAML], true) ? $type : null,
            'application' => $request->filled('application') ? Application::find($request->integer('application')) : null,
            'scopes' => OauthScope::query()->orderBy('key')->get(),
            'nameIdFormats' => ApplicationController::nameIdFormats(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $request->input('type');
        abort_unless(in_array($type, [Provider::TYPE_OIDC, Provider::TYPE_SAML], true), 422);

        if ($type === Provider::TYPE_OIDC) {
            $data = $this->validateOidc($request, creating: true);
            $provider = $this->service->createOidc($data, $request->user());
            $secret = $this->service->plainSecret;
        } else {
            $data = $this->validateSaml($request, creating: true);
            $provider = $this->service->createSaml($data, $request->user());
            $secret = null;
        }

        if ($request->filled('application')) {
            Application::find($request->integer('application'))?->update(['provider_id' => $provider->id]);
        }

        return redirect()->route('admin.providers.show', $provider)
            ->with('status', 'Provider wurde angelegt.')
            ->with('plain_client_secret', $secret);
    }

    public function show(Request $request, Provider $provider): View
    {
        $provider->load(['oauthClient.redirectUris', 'samlServiceProvider.attributeMappings', 'applications']);

        return view('admin.providers.show', [
            'provider' => $provider,
            'tab' => $request->query('tab', 'allgemein'),
            'scopes' => OauthScope::query()->orderBy('key')->get(),
            'nameIdFormats' => ApplicationController::nameIdFormats(),
        ]);
    }

    public function update(Request $request, Provider $provider): RedirectResponse
    {
        $section = $request->input('section', 'allgemein');

        $data = $provider->isOidc()
            ? $this->validateOidc($request, creating: false)
            : $this->validateSaml($request, creating: false);

        $provider->isOidc()
            ? $this->service->updateOidc($provider, $data, $request->user())
            : $this->service->updateSaml($provider, $data, $request->user());

        return redirect()->route('admin.providers.show', ['provider' => $provider, 'tab' => $section])
            ->with('status', 'Provider wurde aktualisiert.');
    }

    public function destroy(Request $request, Provider $provider): RedirectResponse
    {
        AuditLog::record('oauth.provider_deleted', $request->user(), ['name' => $provider->name, 'type' => $provider->type]);

        // Detail-Zeilen hängen per FK cascade; Anwendungen behalten provider_id = null (nullOnDelete).
        $provider->delete();

        return redirect()->route('admin.providers.index')->with('status', 'Provider wurde gelöscht.');
    }

    public function regenerateSecret(Request $request, Provider $provider): RedirectResponse
    {
        abort_unless($provider->isOidc(), 404);

        $secret = $this->service->regenerateSecret($provider, $request->user());

        return redirect()->route('admin.providers.show', ['provider' => $provider, 'tab' => 'allgemein'])
            ->with('status', 'Neues Client Secret erzeugt.')
            ->with('plain_client_secret', $secret);
    }

    public function storeMapping(Request $request, Provider $provider): RedirectResponse
    {
        abort_unless($provider->isSaml(), 404);

        $data = $request->validate([
            'saml_attribute' => 'required|string|max:255',
            'user_attribute' => 'required|string|max:255',
        ]);

        SamlAttributeMapping::create([
            'saml_service_provider_id' => $provider->samlServiceProvider->id,
            ...$data,
        ]);

        return redirect()->route('admin.providers.show', ['provider' => $provider, 'tab' => 'claims'])
            ->with('status', 'Attribut-Mapping hinzugefügt.');
    }

    public function destroyMapping(Provider $provider, SamlAttributeMapping $mapping): RedirectResponse
    {
        abort_unless($provider->isSaml() && $mapping->saml_service_provider_id === $provider->samlServiceProvider->id, 404);

        $mapping->delete();

        return redirect()->route('admin.providers.show', ['provider' => $provider, 'tab' => 'claims'])
            ->with('status', 'Attribut-Mapping entfernt.');
    }

    /**
     * Jedes Tab-Formular sendet den kompletten Feldsatz seines Abschnitts (Checkboxen
     * inklusive – nicht gesetzt = 0). Nur die tatsächlich übermittelten Felder werden
     * validiert und geschrieben, sodass die anderen Tabs unberührt bleiben.
     */
    private function validateOidc(Request $request, bool $creating): array
    {
        $rules = [
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:255',
            'redirect_uris' => ($creating ? 'required' : 'sometimes').'|string',
            'logout_redirect_uris' => 'nullable|string',
            'scopes' => 'sometimes|array',
            'scopes.*' => 'string|exists:oauth_scopes,key',
            'grant_types' => 'sometimes|array',
            'grant_types.*' => 'in:authorization_code,refresh_token,client_credentials',
            'response_types' => 'sometimes|array',
            'response_types.*' => 'in:code,id_token,token',
            'access_token_lifetime' => ($creating ? 'required' : 'sometimes').'|integer|min:60',
            'refresh_token_lifetime' => ($creating ? 'required' : 'sometimes').'|integer|min:60',
            'id_token_lifetime' => ($creating ? 'required' : 'sometimes').'|integer|min:60',
            'id_token_signed_response_alg' => 'sometimes|in:RS256,RS384,RS512,ES256',
        ];

        $data = $request->validate($rules);

        foreach (['pkce_required', 'secret_required', 'is_active'] as $flag) {
            if ($creating || $request->has("_has_$flag")) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        foreach (['grant_types', 'response_types', 'scopes'] as $arr) {
            if ($request->has("_has_$arr") && ! array_key_exists($arr, $data)) {
                $data[$arr] = [];
            }
        }

        return $data;
    }

    private function validateSaml(Request $request, bool $creating): array
    {
        $spId = $creating ? null : $request->route('provider')->samlServiceProvider->id;

        $rules = [
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:255',
            'entity_id' => ($creating ? 'required' : 'sometimes').'|string|max:255|unique:saml_service_providers,entity_id'.($spId ? ",$spId" : ''),
            'acs_url' => ($creating ? 'required' : 'sometimes').'|url',
            'slo_url' => 'nullable|url',
            'slo_binding' => 'sometimes|string',
            'name_id_format' => ($creating ? 'required' : 'sometimes').'|string',
            'certificate' => 'nullable|string',
            'sign_algorithm' => 'sometimes|in:sha1,sha256,sha384,sha512',
            'session_lifetime_minutes' => 'nullable|integer|min:1',
        ];

        $data = $request->validate($rules);

        foreach (['sign_assertions', 'sign_responses', 'encrypt_assertions', 'want_name_id_encrypted', 'require_signed_requests', 'is_active'] as $flag) {
            if ($creating || $request->has("_has_$flag")) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        return $data;
    }
}
