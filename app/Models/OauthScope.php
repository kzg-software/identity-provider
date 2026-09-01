<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'label', 'description', 'is_default'])]
class OauthScope extends Model
{
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
