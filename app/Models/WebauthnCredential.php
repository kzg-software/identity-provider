<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'name', 'credential_id', 'public_key', 'aaguid',
    'attestation_format', 'transports', 'sign_count', 'discoverable', 'uv', 'last_used_at',
])]
class WebauthnCredential extends Model
{
    protected function casts(): array
    {
        return [
            'transports' => 'array',
            'sign_count' => 'integer',
            'discoverable' => 'boolean',
            'uv' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
