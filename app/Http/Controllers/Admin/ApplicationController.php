<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessPolicy;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\OauthClient;
use App\Models\OauthRedirectUri;
use App\Models\OauthScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    public function index(): View
    {
        $applications = Application::query()->with('oauthClients')->orderBy('name')->get();

        return view('admin.applications.index', compact('applications'));
    }

    public function create(): View
    {
        $scopes = OauthScope::query()->orderBy('key')->get();
        $categories = $this->existingCategories();

        return view('admin.applications.create', compact('scopes', 'categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'launch_url' => 'nullable|url|max:255',
            'category' => 'nullable|string|max:100',
            'login_mode' => 'required|in:user_choice,auto_redirect,windows_sso,windows_sso_fallback,specific_provider',
            'preferred_provider' => 'nullable|string|max:255',
            'consent_required' => 'nullable|boolean',
            'consent_mode' => 'required|in:always,first_time,skip,on_scope_change',
            'redirect_uris' => 'required|string',
            'logout_redirect_uris' => 'nullable|string',
            'scopes' => 'array',
            'scopes.*' => 'string|exists:oauth_scopes,key',
            'grant_types' => 'array',
            'grant_types.*' => 'in:authorization_code,refresh_token,client_credentials',
            'access_token_lifetime' => 'required|integer|min:60',
            'refresh_token_lifetime' => 'required|integer|min:60',
            'id_token_lifetime' => 'required|integer|min:60',
            'pkce_required' => 'nullable|boolean',
            'secret_required' => 'nullable|boolean',
        ]);

        $application = Application::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
            'description' => $data['description'] ?? null,
            'launch_url' => $data['launch_url'] ?? null,
            'category' => $data['category'] ?? null,
            'login_mode' => $data['login_mode'],
            'preferred_provider' => $data['preferred_provider'] ?? null,
            'consent_required' => $request->boolean('consent_required'),
            'consent_mode' => $data['consent_mode'],
            'is_active' => true,
        ]);

        $plainSecret = $request->boolean('secret_required') ? Str::random(48) : null;

        $client = OauthClient::create([
            'application_id' => $application->id,
            'name' => $data['name'],
            'client_id' => (string) Str::uuid(),
            'client_secret' => $plainSecret,
            'allowed_grant_types' => $data['grant_types'] ?? ['authorization_code', 'refresh_token'],
            'access_token_lifetime' => $data['access_token_lifetime'],
            'refresh_token_lifetime' => $data['refresh_token_lifetime'],
            'id_token_lifetime' => $data['id_token_lifetime'],
            'pkce_required' => $request->boolean('pkce_required'),
            'secret_required' => $request->boolean('secret_required'),
            'is_active' => true,
        ]);

        foreach (preg_split('/\r?\n/', trim($data['redirect_uris'])) as $uri) {
            if ($uri = trim($uri)) {
                OauthRedirectUri::create(['oauth_client_id' => $client->id, 'uri' => $uri, 'type' => 'login']);
            }
        }

        foreach (preg_split('/\r?\n/', trim($data['logout_redirect_uris'] ?? '')) as $uri) {
            if ($uri = trim($uri)) {
                OauthRedirectUri::create(['oauth_client_id' => $client->id, 'uri' => $uri, 'type' => 'logout']);
            }
        }

        AuditLog::record('oauth.client_created', $request->user(), ['application' => $application->name], $application);

        return redirect()->route('admin.applications.show', $application)
            ->with('status', 'Anwendung wurde angelegt.')
            ->with('plain_client_secret', $plainSecret);
    }

    public function show(Application $application): View
    {
        $application->load(['oauthClients.redirectUris', 'accessPolicies']);
        $scopes = OauthScope::query()->orderBy('key')->get();
        $categories = $this->existingCategories();

        return view('admin.applications.show', compact('application', 'scopes', 'categories'));
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function existingCategories()
    {
        return Application::query()->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->pluck('category');
    }

    public function update(Request $request, Application $application): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'launch_url' => 'nullable|url|max:255',
            'category' => 'nullable|string|max:100',
            'login_mode' => 'required|in:user_choice,auto_redirect,windows_sso,windows_sso_fallback,specific_provider',
            'preferred_provider' => 'nullable|string|max:255',
            'consent_required' => 'nullable|boolean',
            'consent_mode' => 'required|in:always,first_time,skip,on_scope_change',
            'is_active' => 'nullable|boolean',
            'maintenance_mode' => 'nullable|boolean',
            'maintenance_message' => 'nullable|string|max:2000',
            'maintenance_allow' => 'nullable|string|max:4000',
        ]);

        $application->update([
            ...$data,
            'consent_required' => $request->boolean('consent_required'),
            'is_active' => $request->boolean('is_active'),
            'maintenance_mode' => $request->boolean('maintenance_mode'),
            'maintenance_message' => $data['maintenance_message'] ?? null,
            'maintenance_allow' => $data['maintenance_allow'] ?? null,
        ]);

        AuditLog::record('oauth.application_updated', $request->user(), [], $application);

        return back()->with('status', 'Anwendung wurde aktualisiert.');
    }

    public function destroy(Request $request, Application $application): RedirectResponse
    {
        AuditLog::record('oauth.application_deleted', $request->user(), ['name' => $application->name]);
        $application->delete();

        return redirect()->route('admin.applications.index')->with('status', 'Anwendung wurde gelöscht.');
    }

    public function updateClient(Request $request, Application $application, OauthClient $client): RedirectResponse
    {
        abort_unless($client->application_id === $application->id, 404);

        $data = $request->validate([
            'redirect_uris' => 'required|string',
            'logout_redirect_uris' => 'nullable|string',
            'grant_types' => 'array',
            'grant_types.*' => 'in:authorization_code,refresh_token,client_credentials',
            'access_token_lifetime' => 'required|integer|min:60',
            'refresh_token_lifetime' => 'required|integer|min:60',
            'id_token_lifetime' => 'required|integer|min:60',
            'pkce_required' => 'nullable|boolean',
            'secret_required' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $client->update([
            'allowed_grant_types' => $data['grant_types'] ?? [],
            'access_token_lifetime' => $data['access_token_lifetime'],
            'refresh_token_lifetime' => $data['refresh_token_lifetime'],
            'id_token_lifetime' => $data['id_token_lifetime'],
            'pkce_required' => $request->boolean('pkce_required'),
            'secret_required' => $request->boolean('secret_required'),
            'is_active' => $request->boolean('is_active'),
        ]);

        $client->redirectUris()->delete();
        foreach (preg_split('/\r?\n/', trim($data['redirect_uris'])) as $uri) {
            if ($uri = trim($uri)) {
                OauthRedirectUri::create(['oauth_client_id' => $client->id, 'uri' => $uri, 'type' => 'login']);
            }
        }
        foreach (preg_split('/\r?\n/', trim($data['logout_redirect_uris'] ?? '')) as $uri) {
            if ($uri = trim($uri)) {
                OauthRedirectUri::create(['oauth_client_id' => $client->id, 'uri' => $uri, 'type' => 'logout']);
            }
        }

        AuditLog::record('oauth.client_updated', $request->user(), [], $application);

        return back()->with('status', 'OAuth-Client wurde aktualisiert.');
    }

    public function regenerateSecret(Request $request, Application $application, OauthClient $client): RedirectResponse
    {
        abort_unless($client->application_id === $application->id, 404);

        $plainSecret = Str::random(48);
        $client->update(['client_secret' => $plainSecret]);

        AuditLog::record('oauth.client_secret_regenerated', $request->user(), [], $application);

        return back()->with('status', 'Neues Client Secret erzeugt.')->with('plain_client_secret', $plainSecret);
    }

    public function storePolicy(Request $request, Application $application): RedirectResponse
    {
        $data = $request->validate([
            'effect' => 'required|in:allow,deny',
            'subject_type' => 'required|in:user,group,domain',
            'subject_value' => 'required|string|max:255',
            'priority' => 'nullable|integer',
        ]);

        AccessPolicy::create([
            'application_id' => $application->id,
            'effect' => $data['effect'],
            'subject_type' => $data['subject_type'],
            'subject_value' => $data['subject_value'],
            'priority' => $data['priority'] ?? 0,
        ]);

        AuditLog::record('oauth.access_policy_created', $request->user(), $data, $application);

        return back()->with('status', 'Zugriffsregel wurde angelegt.');
    }

    public function destroyPolicy(Request $request, Application $application, AccessPolicy $policy): RedirectResponse
    {
        abort_unless($policy->application_id === $application->id, 404);
        $policy->delete();

        AuditLog::record('oauth.access_policy_deleted', $request->user(), [], $application);

        return back()->with('status', 'Zugriffsregel wurde gelöscht.');
    }
}
