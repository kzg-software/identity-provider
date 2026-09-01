<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'type', 'certificate', 'private_key_encrypted', 'fingerprint', 'algorithm', 'issued_at', 'expires_at', 'is_active'])]
#[Hidden(['private_key_encrypted'])]
class SamlCertificate extends Model
{
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'private_key_encrypted' => 'encrypted',
        ];
    }
}
