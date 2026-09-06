<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'is_break_glass', 'failed_login_attempts', 'locked_until', 'password_changed_at',
])]
class LocalAccount extends Model
{
    protected function casts(): array
    {
        return [
            'is_break_glass' => 'boolean',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
