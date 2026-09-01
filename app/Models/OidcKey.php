<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kid', 'algorithm', 'public_key', 'private_key_encrypted', 'is_active', 'rotated_at'])]
#[Hidden(['private_key_encrypted'])]
class OidcKey extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rotated_at' => 'datetime',
            'private_key_encrypted' => 'encrypted',
        ];
    }
}
