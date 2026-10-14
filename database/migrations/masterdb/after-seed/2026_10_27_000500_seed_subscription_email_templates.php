<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The emails the subscription reminders send — editable afterwards like every
 * other platform template, by template key.
 *
 * updateOrInsert by key, so replaying this never duplicates a template; an
 * administrator's later edit to the wording is overwritten only if this
 * migration is rolled back and run again.
 */
return new class extends Migration
{
    private const TEMPLATES = [
        [
            'template_key' => 'subscription_expiring',
            'template_name' => 'Subscription expiring',
            'subject' => 'Your ZEVRO subscription ends {ends_when}',
            'available_placeholders' => 'organization_name,plan_name,ends_when,expires_on,days_left,renew_button',
            'body' => '
<h2>Your subscription ends {ends_when}</h2>
<p>Hello,</p>
<p>The <strong>{plan_name}</strong> subscription for <strong>{organization_name}</strong> ends on <strong>{expires_on}</strong> ({days_left}).</p>
<p>Renew before then to keep working without interruption.</p>
<p>{renew_button}</p>
<p>Thank you,<br>Team ZEVRO</p>
',
        ],
        [
            'template_key' => 'subscription_grace',
            'template_name' => 'Subscription in grace period',
            'subject' => 'Your ZEVRO subscription has expired: {grace_left} to renew',
            'available_placeholders' => 'organization_name,plan_name,expired_on,grace_ends_on,grace_left,renew_button',
            'body' => '
<h2>Your subscription has expired</h2>
<p>Hello,</p>
<p>The <strong>{plan_name}</strong> subscription for <strong>{organization_name}</strong> ended on <strong>{expired_on}</strong>.</p>
<p>Your workspace keeps working during a grace period until <strong>{grace_ends_on}</strong> ({grace_left}). After that it will be locked until the subscription is renewed. Nothing will be deleted.</p>
<p>{renew_button}</p>
<p>Thank you,<br>Team ZEVRO</p>
',
        ],
        [
            'template_key' => 'subscription_locked',
            'template_name' => 'Subscription locked',
            'subject' => 'Your ZEVRO workspace is locked',
            'available_placeholders' => 'organization_name,plan_name,locked_on,renew_button',
            'body' => '
<h2>Your workspace is locked</h2>
<p>Hello,</p>
<p>The subscription for <strong>{organization_name}</strong> has ended, and the workspace was locked on <strong>{locked_on}</strong>.</p>
<p>All of your data is safe: nothing has been deleted. Renew the subscription to restore access straight away.</p>
<p>{renew_button}</p>
<p>Thank you,<br>Team ZEVRO</p>
',
        ],
        [
            'template_key' => 'subscription_renewed',
            'template_name' => 'Subscription renewed',
            'subject' => 'Payment received: your ZEVRO subscription is renewed',
            'available_placeholders' => 'organization_name,plan_name,amount,invoice_number,expires_on',
            'body' => '
<h2>Thank you, your subscription is renewed</h2>
<p>Hello,</p>
<p>We have received <strong>{amount}</strong> for invoice <strong>{invoice_number}</strong>.</p>
<p>The <strong>{plan_name}</strong> subscription for <strong>{organization_name}</strong> now runs until <strong>{expires_on}</strong>.</p>
<p>Thank you,<br>Team ZEVRO</p>
',
        ],
    ];

    public function up(): void
    {
        foreach (self::TEMPLATES as $template) {
            DB::table('email_templates')->updateOrInsert(
                ['template_key' => $template['template_key']],
                [
                    ...$template,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->whereIn('template_key', array_column(self::TEMPLATES, 'template_key'))
            ->delete();
    }
};
