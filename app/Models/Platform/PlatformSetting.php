<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

/** Generic platform-wide key/value store. */
class PlatformSetting extends Model
{
    /** The sending account every organization's WhatsApp goes out through. */
    public const WHATSAPP = 'whatsapp.provider';

    /** The mail relay every organization's email goes out through. */
    public const EMAIL = 'email.provider';

    protected $fillable = [
        'key',
        'value',
        'secret',
    ];

    protected $casts = [
        'value' => 'array',

        // Kept apart from `value` so the readable half stays readable. A
        // token in a settings table that anybody with database access can
        // read is a token that has already leaked.
        'secret' => 'encrypted:array',
    ];

    /**
     * One setting by key, existing or not.
     *
     * Returns an unsaved model rather than null so callers can read
     * `->value` without checking first — a setting that has never been
     * written and one written empty mean the same thing to everything that
     * reads this table.
     */
    public static function forKey(string $key): self
    {
        return static::query()->firstOrNew(['key' => $key]);
    }
}
