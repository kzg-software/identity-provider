@props([
    'type' => null,        // 'oidc' | 'saml' | null
    'provider' => null,     // optional: App\Models\Provider
])

@php
    $type = $provider?->type ?? $type;
    [$label, $color] = match ($type) {
        'oidc' => ['OAuth2 / OIDC', 'green'],
        'saml' => ['SAML 2.0', 'laravel'],
        default => ['Kein Provider', 'gray'],
    };
@endphp

<x-badge :color="$color" {{ $attributes }}>{{ $label }}</x-badge>
