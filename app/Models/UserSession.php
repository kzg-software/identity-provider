<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'user_id', 'session_id', 'ip_address', 'user_agent', 'device', 'browser',
    'platform', 'login_method', 'login_at', 'last_activity_at', 'revoked_at',
])]
class UserSession extends Model
{
    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Only rows that are BOTH not explicitly revoked AND whose underlying
     * Laravel session (`sessions` table) still genuinely exists and hasn't
     * timed out. Without the second half of this check, a session that
     * simply expired (browser closed, no explicit logout) or was garbage
     * collected would linger in "Meine Sitzungen"/"Alle Sessions" forever,
     * since nothing else ever sets `revoked_at` for that case.
     *
     * Only meaningful with the database session driver (SESSION_DRIVER),
     * which this application requires - see config/session.php.
     */
    public function scopeActive(Builder $query): Builder
    {
        $cutoff = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        $liveSessionIds = DB::table((string) config('session.table', 'sessions'))
            ->where('last_activity', '>=', $cutoff)
            ->pluck('id');

        return $query->whereNull('revoked_at')->whereIn('session_id', $liveSessionIds);
    }
}
