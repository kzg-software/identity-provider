<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scim_connection_id', 'resource_type', 'local_id', 'remote_id', 'payload_hash', 'deactivated', 'synced_at'])]
class ScimResource extends Model
{
    public const USER = 'user';

    public const GROUP = 'group';

    protected function casts(): array
    {
        return [
            'deactivated' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ScimConnection::class, 'scim_connection_id');
    }
}
