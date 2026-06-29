<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $table = 'system_settings';

    protected $fillable = [
        'tenant_id',
        'key',
        'value',
        'group',
    ];

    /**
     * Get a setting value for a tenant, with a fallback default.
     */
    public static function get(int $tenantId, string $key, mixed $default = null): mixed
    {
        $row = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->first();

        return $row ? $row->value : $default;
    }
}
