<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'type', 'level', 'title', 'body', 'action_url', 'dedupe_key', 'read_at'])]
class Notification extends Model
{
    protected $table = 'user_notifications';

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function markRead(): void
    {
        if ($this->isUnread()) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    /** Icon-Name (siehe <x-icon>) passend zum Benachrichtigungstyp. */
    public function iconName(): string
    {
        return match (true) {
            str_starts_with($this->type, 'system.update') => 'sparkles',
            str_starts_with($this->type, 'system.expiry') => 'lock-closed',
            str_starts_with($this->type, 'system.backup') => 'download',
            str_starts_with($this->type, 'system.scheduler') => 'heart-pulse',
            str_starts_with($this->type, 'system.directory') => 'server',
            str_starts_with($this->type, 'system.logins') => 'warning',
            str_starts_with($this->type, 'security.') => 'shield-check',
            default => 'info',
        };
    }

    /** Tailwind-Klassen für den farbigen Punkt/Rahmen nach Dringlichkeit. */
    public function accentClasses(): string
    {
        return match ($this->level) {
            'critical' => 'text-red-600 bg-red-50',
            'warning' => 'text-amber-600 bg-amber-50',
            'success' => 'text-emerald-600 bg-emerald-50',
            default => 'text-laravel-600 bg-laravel-50',
        };
    }
}
