<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['saml_service_provider_id', 'saml_attribute', 'user_attribute'])]
class SamlAttributeMapping extends Model
{
    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(SamlServiceProvider::class);
    }
}
