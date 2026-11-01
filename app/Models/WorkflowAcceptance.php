<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Merkt, dass ein Konto einen Hinweis bzw. eine Version der
 * Nutzungsbedingungen aus einem Workflow bestätigt hat.
 */
#[Fillable(['user_id', 'document_key', 'document_version', 'accepted_at'])]
class WorkflowAcceptance extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public static function has(int $userId, string $key, string $version): bool
    {
        return self::query()->where([
            'user_id' => $userId,
            'document_key' => $key,
            'document_version' => $version,
        ])->exists();
    }

    public static function record(int $userId, string $key, string $version): void
    {
        self::query()->firstOrCreate(
            ['user_id' => $userId, 'document_key' => $key, 'document_version' => $version],
            ['accepted_at' => now()],
        );
    }
}
