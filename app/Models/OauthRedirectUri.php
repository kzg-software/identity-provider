<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['oauth_client_id', 'uri', 'type'])]
class OauthRedirectUri extends Model
{
    public function client(): BelongsTo
    {
        return $this->belongsTo(OauthClient::class, 'oauth_client_id');
    }
}
