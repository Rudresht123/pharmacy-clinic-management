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
                'template_key' => 'organization_setup',
                'template_name' => 'Organization Setup',
                'subject' => 'Welcome to HMS - Complete Your Organization Setup',
                'body' => '
<h2>🎉 Welcome to HMS</h2>

<p>Hello,</p>

<p>
Your organization <strong>{organization_name}</strong> has been successfully created.
</p>

<p>
You are just one step away from accessing your HMS account.
</p>

<p>
Please click the button below to create your administrator account.
</p>

<p>
{setup_button}
</p>

<p>
This link will expire on <strong>{expiry_date}</strong>.
</p>

<p>
If you did not request this account, please ignore this email.
</p>

<p>
Thank you,<br>
Team HMS
</p>
',
                'available_placeholders' => 'organization_name,setup_button,expiry_date',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],

            [
                'template_key' => 'password_reset',
                'template_name' => 'Password Reset',
                'subject' => 'Reset Your HMS Password',
                'body' => '
<h2>🔐 Password Reset Request</h2>

<p>Hello {user_name},</p>

<p>
We received a request to reset the password for your HMS account.
</p>

<p>
To continue, click the button below:
</p>

<p>
{reset_button}
</p>

<p>
This reset link will expire on <strong>{expiry_date}</strong>.
</p>

<p>
If you did not request this password reset, please ignore this email.
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
            [
                'template_key' => 'organization_setup_completed',

                'template_name' => 'Organization Setup Completed',

                'subject' => '🎉 Welcome to HMS - Your Account Is Ready',

                'body' => '

<h2>🎉 Congratulations, {user_name}!</h2>

<p>
Your HMS account setup has been completed successfully.
</p>

<p>
We are pleased to inform you that your organization
<strong>{organization_name}</strong> has been successfully activated and is now ready to use.
</p>

<p>
Your administrator account has been created and you can now access your HMS dashboard to manage your organization efficiently.
</p>

<p>
<strong>Account Information</strong>
</p>

<ul>
    <li><strong>Name:</strong> {user_name}</li>
    <li><strong>Email:</strong> {email}</li>
    <li><strong>Organization:</strong> {organization_name}</li>
</ul>

<p>
Click the button below to sign in to your account and start using HMS:
</p>

<p>
{login_button}
</p>

<p>
With HMS, you can manage:
</p>

<ul>
    <li>Doctors & Medical Staff</li>
    <li>Patient Records</li>
    <li>Appointments & Scheduling</li>
    <li>Billing & Invoices</li>
    <li>Hospital Operations</li>
    <li>Reports & Analytics</li>
</ul>

<p>
For security purposes, please keep your login credentials confidential and do not share them with unauthorized users.
</p>

<p>
If you experience any issues accessing your account, please contact your system administrator or support team.
</p>

<p>
Thank you for choosing HMS.</p>

<p>
We look forward to helping you streamline and manage your healthcare operations efficiently.
</p>

<p>
Best Regards,<br>
<strong>HMS Team</strong>
</p>

',

                'available_placeholders' => 'user_name,email,organization_name,login_button',

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
