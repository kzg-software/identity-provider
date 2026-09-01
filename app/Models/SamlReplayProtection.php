<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['message_id', 'type', 'seen_at'])]
class SamlReplayProtection extends Model
{
    const UPDATED_AT = null;

    const CREATED_AT = null;

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }
}
