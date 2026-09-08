<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'provider_id', 'name', 'entity_id', 'acs_url', 'slo_url', 'slo_binding', 'name_id_format',
    'certificate', 'sign_assertions', 'sign_responses', 'sign_algorithm', 'encrypt_assertions',
    'want_name_id_encrypted', 'require_signed_requests', 'session_lifetime_minutes', 'is_active',
])]
class SamlServiceProvider extends Model
{
    protected function casts(): array
    {
        return [
            'sign_assertions' => 'boolean',
            'sign_responses' => 'boolean',
            'encrypt_assertions' => 'boolean',
            'want_name_id_encrypted' => 'boolean',
            'require_signed_requests' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** Rückwärtskompatibel: die (erste) Anwendung, die diesen SP über den Provider nutzt. */
    protected function application(): Attribute
    {
        return Attribute::get(fn () => $this->provider?->application);
    }

    public function attributeMappings(): HasMany
    {
        return $this->hasMany(SamlAttributeMapping::class);
    }

    /** Gültigkeitsdauer der SAML-Session in Sekunden (Fallback: 8 Stunden). */
    public function sessionLifetimeSeconds(): int
    {
        return ($this->session_lifetime_minutes ?? 0) > 0
            ? $this->session_lifetime_minutes * 60
            : 8 * 3600;
    }
}
