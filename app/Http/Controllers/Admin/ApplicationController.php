<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessPolicy;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\OauthScope;
use App\Models\Provider;
use App\Services\ProviderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    public function __construct(private readonly ProviderService $providers) {}

    public function index(): View
    {
        $applications = Application::query()
            ->with(['provider.oauthClient', 'provider.samlServiceProvider'])
            ->orderBy('name')
            ->get();

        return view('admin.applications.index', compact('applications'));
    }

    public function create(): View
    {
        return view('admin.applications.create', [
            'scopes' => OauthScope::query()->orderBy('key')->get(),
            'categories' => $this->existingCategories(),
            'nameIdFormats' => $this->nameIdFormats(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'launch_url' => 'nullable|url|max:255',
            'category' => 'nullable|string|max:100',
            'visibility' => ['required', Rule::in([Application::VISIBILITY_PORTAL, Application::VISIBILITY_HIDDEN])],
            'provider_mode' => 'required|in:none,oidc,saml',
        ]);

        $application = Application::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'description' => $data['description'] ?? null,
            'launch_url' => $data['launch_url'] ?? null,
            'category' => $data['category'] ?? null,
            'visibility' => $data['visibility'],
            'login_mode' => 'user_choice',
            'consent_required' => true,
            'consent_mode' => 'first_time',
            'is_active' => true,
        ]);

        $plainSecret = null;

        if ($data['provider_mode'] === 'oidc') {
            $providerData = $request->validate([
                'redirect_uris' => 'required|string',
                'logout_redirect_uris' => 'nullable|string',
                'scopes' => 'array',
                'scopes.*' => 'string|exists:oauth_scopes,key',
                'grant_types' => 'array',
                'grant_types.*' => 'in:authorization_code,refresh_token,client_credentials',
                'response_types' => 'array',
                'response_types.*' => 'in:code,id_token,token',
                'access_token_lifetime' => 'required|integer|min:60',
                'refresh_token_lifetime' => 'required|integer|min:60',
                'id_token_lifetime' => 'required|integer|min:60',
                'id_token_signed_response_alg' => 'nullable|in:RS256,RS384,RS512,ES256',
                'pkce_required' => 'nullable|boolean',
                'secret_required' => 'nullable|boolean',
            ]);
            $providerData['name'] = $data['name'];
            $providerData['pkce_required'] = $request->boolean('pkce_required');
            $providerData['secret_required'] = $request->boolean('secret_required');

            $provider = $this->providers->createOidc($providerData, $request->user());
            $plainSecret = $this->providers->plainSecret;
            $application->update(['provider_id' => $provider->id]);
        } elseif ($data['provider_mode'] === 'saml') {
            $providerData = $request->validate([
                'entity_id' => 'required|string|max:255|unique:saml_service_providers,entity_id',
                'acs_url' => 'required|url',
                'slo_url' => 'nullable|url',
                'name_id_format' => 'required|string',
                'certificate' => 'nullable|string',
                'sign_assertions' => 'nullable|boolean',
                'sign_responses' => 'nullable|boolean',
                'encrypt_assertions' => 'nullable|boolean',
                'require_signed_requests' => 'nullable|boolean',
                'session_lifetime_minutes' => 'nullable|integer|min:1',
            ]);
            $providerData['name'] = $data['name'];
            foreach (['sign_assertions', 'sign_responses', 'encrypt_assertions', 'require_signed_requests'] as $flag) {
                $providerData[$flag] = $request->boolean($flag);
            }

            $provider = $this->providers->createSaml($providerData, $request->user());
            $application->update(['provider_id' => $provider->id]);
        }

        AuditLog::record('oauth.application_created', $request->user(), ['application' => $application->name], $application);

        return redirect()->route('admin.applications.show', ['application' => $application, 'tab' => 'provider'])
            ->with('status', 'Anwendung wurde angelegt.')
            ->with('plain_client_secret', $plainSecret);
    }

    public function show(Request $request, Application $application): View
    {
        $application->load(['provider.oauthClient.redirectUris', 'provider.samlServiceProvider.attributeMappings', 'accessPolicies']);

        return view('admin.applications.show', [
            'application' => $application,
            'tab' => $request->query('tab', 'allgemein'),
            'categories' => $this->existingCategories(),
            'unassignedProviders' => Provider::query()->whereDoesntHave('applications')->orderBy('name')->get(),
            'allProviders' => Provider::query()->with('applications')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Application $application): RedirectResponse
    {
        $section = $request->input('section', 'allgemein');

        if ($section === 'darstellung') {
            $data = $request->validate([
                'visibility' => ['required', Rule::in([Application::VISIBILITY_PORTAL, Application::VISIBILITY_HIDDEN])],
                'logo' => 'nullable|image|mimes:png,jpg,jpeg,gif,webp|max:5120',
            ]);

            if ($request->hasFile('logo')) {
                if ($application->logo_path) {
                    Storage::disk('public')->delete($application->logo_path);
                }
                $application->logo_path = $request->file('logo')->store('app-logos', 'public');
            }

            $application->visibility = $data['visibility'];
            $application->save();
        } else {
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
        }

        AuditLog::record('oauth.application_updated', $request->user(), ['section' => $section], $application);

        return redirect()->route('admin.applications.show', ['application' => $application, 'tab' => $section])
            ->with('status', 'Anwendung wurde aktualisiert.');
    }

    public function attachProvider(Request $request, Application $application): RedirectResponse
    {
        $data = $request->validate(['provider_id' => 'required|exists:providers,id']);

        $application->update(['provider_id' => (int) $data['provider_id']]);

        AuditLog::record('oauth.application_provider_attached', $request->user(), ['provider_id' => $data['provider_id']], $application);

        return redirect()->route('admin.applications.show', ['application' => $application, 'tab' => 'provider'])
            ->with('status', 'Provider wurde zugewiesen.');
    }

    public function detachProvider(Request $request, Application $application): RedirectResponse
    {
        $application->update(['provider_id' => null]);

        AuditLog::record('oauth.application_provider_detached', $request->user(), [], $application);

        return redirect()->route('admin.applications.show', ['application' => $application, 'tab' => 'provider'])
            ->with('status', 'Provider wurde entfernt. Der Provider selbst bleibt bestehen.');
    }

    public function destroy(Request $request, Application $application): RedirectResponse
    {
        AuditLog::record('oauth.application_deleted', $request->user(), ['name' => $application->name]);

        if ($application->logo_path) {
            Storage::disk('public')->delete($application->logo_path);
        }

        $application->delete();

        return redirect()->route('admin.applications.index')->with('status', 'Anwendung wurde gelöscht. Ein zugeordneter Provider bleibt bestehen.');
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

        return redirect()->route('admin.applications.show', ['application' => $application, 'tab' => 'zugriff'])
            ->with('status', 'Zugriffsregel wurde angelegt.');
    }

    public function destroyPolicy(Request $request, Application $application, AccessPolicy $policy): RedirectResponse
    {
        abort_unless($policy->application_id === $application->id, 404);
        $policy->delete();

        AuditLog::record('oauth.access_policy_deleted', $request->user(), [], $application);

        return redirect()->route('admin.applications.show', ['application' => $application, 'tab' => 'zugriff'])
            ->with('status', 'Zugriffsregel wurde gelöscht.');
    }

    /**
     * @return Collection<int, string>
     */
    private function existingCategories()
    {
        return Application::query()->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->pluck('category');
    }

    private function uniqueSlug(string $name): string
    {
        return Str::slug($name).'-'.Str::lower(Str::random(6));
    }

    /**
     * @return array<string, string>
     */
    public static function nameIdFormats(): array
    {
        return [
            'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent' => 'persistent',
            'urn:oasis:names:tc:SAML:2.0:nameid-format:transient' => 'transient',
            'urn:oasis:names:tc:SAML:2.0:nameid-format:emailAddress' => 'emailAddress',
            'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified' => 'unspecified',
        ];
    }
}
