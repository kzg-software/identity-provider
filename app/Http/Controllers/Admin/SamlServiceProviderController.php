<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessPolicy;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\SamlAttributeMapping;
use App\Models\SamlServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SamlServiceProviderController extends Controller
{
    private const DEFAULT_MAPPINGS = [
        'uid' => 'username',
        'mail' => 'email',
        'displayName' => 'display_name',
        'department' => 'department',
        'groups' => 'groups',
    ];

    public function index(): View
    {
        $providers = SamlServiceProvider::query()->with('application')->orderBy('name')->get();

        return view('admin.saml-service-providers.index', compact('providers'));
    }

    public function create(): View
    {
        return view('admin.saml-service-providers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'entity_id' => 'required|string|max:255|unique:saml_service_providers,entity_id',
            'acs_url' => 'required|url',
            'slo_url' => 'nullable|url',
            'name_id_format' => 'required|string',
            'certificate' => 'nullable|string',
            'sign_assertions' => 'nullable|boolean',
            'sign_responses' => 'nullable|boolean',
            'encrypt_assertions' => 'nullable|boolean',
            'require_signed_requests' => 'nullable|boolean',
            'login_mode' => 'required|in:user_choice,auto_redirect,windows_sso,windows_sso_fallback,specific_provider',
            'consent_mode' => 'required|in:always,first_time,skip,on_scope_change',
        ]);

        $application = Application::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
            'description' => 'SAML Service Provider',
            'login_mode' => $data['login_mode'],
            'consent_required' => false,
            'consent_mode' => $data['consent_mode'],
            'is_active' => true,
        ]);

        $sp = SamlServiceProvider::create([
            'application_id' => $application->id,
            'name' => $data['name'],
            'entity_id' => $data['entity_id'],
            'acs_url' => $data['acs_url'],
            'slo_url' => $data['slo_url'] ?? null,
            'name_id_format' => $data['name_id_format'],
            'certificate' => $data['certificate'] ?? null,
            'sign_assertions' => $request->boolean('sign_assertions', true),
            'sign_responses' => $request->boolean('sign_responses', true),
            'encrypt_assertions' => $request->boolean('encrypt_assertions'),
            'require_signed_requests' => $request->boolean('require_signed_requests'),
            'is_active' => true,
        ]);

        foreach (self::DEFAULT_MAPPINGS as $samlAttribute => $userAttribute) {
            SamlAttributeMapping::create([
                'saml_service_provider_id' => $sp->id,
                'saml_attribute' => $samlAttribute,
                'user_attribute' => $userAttribute,
            ]);
        }

        AuditLog::record('saml.service_provider_created', $request->user(), ['entity_id' => $sp->entity_id], $application);

        return redirect()->route('admin.saml-service-providers.show', $sp)->with('status', 'SAML Service Provider wurde angelegt.');
    }

    public function show(SamlServiceProvider $samlServiceProvider): View
    {
        $samlServiceProvider->load(['application.accessPolicies', 'attributeMappings']);

        return view('admin.saml-service-providers.show', ['sp' => $samlServiceProvider]);
    }

    public function update(Request $request, SamlServiceProvider $samlServiceProvider): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'entity_id' => 'required|string|max:255|unique:saml_service_providers,entity_id,'.$samlServiceProvider->id,
            'acs_url' => 'required|url',
            'slo_url' => 'nullable|url',
            'name_id_format' => 'required|string',
            'certificate' => 'nullable|string',
            'sign_assertions' => 'nullable|boolean',
            'sign_responses' => 'nullable|boolean',
            'encrypt_assertions' => 'nullable|boolean',
            'require_signed_requests' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $samlServiceProvider->update([
            ...$data,
            'sign_assertions' => $request->boolean('sign_assertions'),
            'sign_responses' => $request->boolean('sign_responses'),
            'encrypt_assertions' => $request->boolean('encrypt_assertions'),
            'require_signed_requests' => $request->boolean('require_signed_requests'),
            'is_active' => $request->boolean('is_active'),
        ]);

        AuditLog::record('saml.service_provider_updated', $request->user(), [], $samlServiceProvider->application);

        return back()->with('status', 'Service Provider wurde aktualisiert.');
    }

    public function destroy(Request $request, SamlServiceProvider $samlServiceProvider): RedirectResponse
    {
        AuditLog::record('saml.service_provider_deleted', $request->user(), ['entity_id' => $samlServiceProvider->entity_id]);
        $application = $samlServiceProvider->application;
        $samlServiceProvider->delete();
        $application?->delete();

        return redirect()->route('admin.saml-service-providers.index')->with('status', 'Service Provider wurde gelöscht.');
    }

    public function storeMapping(Request $request, SamlServiceProvider $samlServiceProvider): RedirectResponse
    {
        $data = $request->validate([
            'saml_attribute' => 'required|string|max:255',
            'user_attribute' => 'required|string|max:255',
        ]);

        SamlAttributeMapping::create([
            'saml_service_provider_id' => $samlServiceProvider->id,
            ...$data,
        ]);

        return back()->with('status', 'Attribut-Mapping hinzugefügt.');
    }

    public function destroyMapping(SamlServiceProvider $samlServiceProvider, SamlAttributeMapping $mapping): RedirectResponse
    {
        abort_unless($mapping->saml_service_provider_id === $samlServiceProvider->id, 404);
        $mapping->delete();

        return back()->with('status', 'Attribut-Mapping entfernt.');
    }

    public function storePolicy(Request $request, SamlServiceProvider $samlServiceProvider): RedirectResponse
    {
        $data = $request->validate([
            'effect' => 'required|in:allow,deny',
            'subject_type' => 'required|in:user,group,domain',
            'subject_value' => 'required|string|max:255',
            'priority' => 'nullable|integer',
        ]);

        AccessPolicy::create([
            'application_id' => $samlServiceProvider->application_id,
            ...$data,
            'priority' => $data['priority'] ?? 0,
        ]);

        return back()->with('status', 'Zugriffsregel wurde angelegt.');
    }

    public function destroyPolicy(SamlServiceProvider $samlServiceProvider, AccessPolicy $policy): RedirectResponse
    {
        abort_unless($policy->application_id === $samlServiceProvider->application_id, 404);
        $policy->delete();

        return back()->with('status', 'Zugriffsregel wurde gelöscht.');
    }
}
