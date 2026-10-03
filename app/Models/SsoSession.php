<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'session_id', 'protocol', 'saml_service_provider_id', 'oauth_client_id', 'name_id', 'session_index'])]
class SsoSession extends Model
{
    public const SAML = 'saml';

    public const OIDC = 'oidc';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function samlServiceProvider(): BelongsTo
    {
        return $this->belongsTo(SamlServiceProvider::class);
    }

    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(OauthClient::class);
    }
}
