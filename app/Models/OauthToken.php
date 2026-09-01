<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'oauth_client_id', 'type', 'identifier', 'token_hash', 'scopes', 'metadata', 'revoked', 'expires_at'])]
class OauthToken extends Model
{
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'metadata' => 'array',
            'revoked' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(OauthClient::class, 'oauth_client_id');
    }
}
