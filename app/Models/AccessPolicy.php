<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['application_id', 'effect', 'subject_type', 'subject_value', 'priority'])]
class AccessPolicy extends Model
{
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
