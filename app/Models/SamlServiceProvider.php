<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'application_id', 'name', 'entity_id', 'acs_url', 'slo_url', 'name_id_format',
    'certificate', 'sign_assertions', 'sign_responses', 'encrypt_assertions',
    'require_signed_requests', 'is_active',
])]
class SamlServiceProvider extends Model
{
    protected function casts(): array
    {
        return [
            'sign_assertions' => 'boolean',
            'sign_responses' => 'boolean',
            'encrypt_assertions' => 'boolean',
            'require_signed_requests' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function attributeMappings(): HasMany
    {
        return $this->hasMany(SamlAttributeMapping::class);
    }
}
