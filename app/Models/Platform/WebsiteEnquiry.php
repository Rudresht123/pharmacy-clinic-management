<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;

/**
 * A demo, trial or contact request from the marketing website — a lead, not
 * yet an organization. Written only by the public enquiry endpoint.
 */
class WebsiteEnquiry extends Model
{
    public const DEMO = 'demo';

    public const TRIAL = 'trial';

    public const CONTACT = 'contact';

    public const TYPES = [self::DEMO, self::TRIAL, self::CONTACT];

    /** Nobody has replied yet. */
    public const NEW = 'new';

    public const CONTACTED = 'contacted';

    public const CONVERTED = 'converted';

    public const CLOSED = 'closed';

    public const STATUSES = [self::NEW, self::CONTACTED, self::CONVERTED, self::CLOSED];

    protected $fillable = [
        'type',
        'name',
        'organization_name',
        'email',
        'phone',
        'interest',
        'message',
        'status',
        'ip_address',
    ];
}
