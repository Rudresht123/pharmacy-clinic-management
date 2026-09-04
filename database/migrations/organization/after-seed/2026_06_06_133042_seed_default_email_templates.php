<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('email_templates')->insert([
            [
                'template_key' => 'password_reset',
                'template_name' => 'Password Reset',
                'subject' => 'Reset Your HMS Password',
                'body' => '
<h2>🔐 Password Reset Request</h2>

<p>Hello <strong>{user_name}</strong>,</p>

<p>
We received a request to reset the password for your HMS account.
</p>

<p>
Please click the button below to create a new password:
</p>

<p>
{reset_button}
</p>

<p>
This link will expire on <strong>{expiry_date}</strong>.
</p>

<p>
If you did not request a password reset, please ignore this email.
</p>

<p>
Regards,<br>
Team HMS
</p>
',
                'available_placeholders' => 'user_name,reset_button,expiry_date',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'template_key' => 'user_account_setup',
                'template_name' => 'User Account Setup',
                'subject' => 'Complete Your HMS Account Setup',
                'body' => '
<h2>👋 Welcome to HMS</h2>

<p>Hello <strong>{user_name}</strong>,</p>

<p>
An account has been created for you in
<strong>{organization_name}</strong>.
</p>

<p>
Your assigned role is:
<strong>{role}</strong>
</p>

<p>
Before accessing HMS, please create your password using the button below.
</p>

<p>
{setup_button}
</p>

<p>
This setup link will expire on <strong>{expiry_date}</strong>.
</p>

<p>
If you were not expecting this email, please contact your administrator.
</p>

<p>
Thank you,<br>
Team HMS
</p>
',
                'available_placeholders' => 'user_name,role,organization_name,setup_button,expiry_date',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
