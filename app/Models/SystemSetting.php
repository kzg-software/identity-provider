<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value'])]
class SystemSetting extends Model
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("system_setting:{$key}", function () use ($key, $default) {
            return static::query()->where('key', $key)->value('value') ?? $default;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("system_setting:{$key}");
    }

    public static function isInstalled(): bool
    {
        return static::get('installed') === '1';
    }
}
