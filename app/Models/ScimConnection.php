<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

#[Fillable([
    'application_id', 'base_url', 'auth_token', 'sync_users', 'sync_groups', 'on_removal',
    'is_active', 'last_synced_at', 'last_status', 'last_error',
])]
#[Hidden(['auth_token'])]
class ScimConnection extends Model
{
    public const REMOVAL_DEACTIVATE = 'deactivate';

    public const REMOVAL_DELETE = 'delete';

    protected static function booted(): void
    {
        $forget = fn () => Cache::forget('scim.has_active_connection');

        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return [
            'auth_token' => 'encrypted',
            'sync_users' => 'boolean',
            'sync_groups' => 'boolean',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(ScimResource::class);
    }

    public static function anyActive(): bool
    {
        return Cache::remember('scim.has_active_connection', 300, fn () => self::query()->where('is_active', true)->exists());
    }
}
