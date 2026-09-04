<?php

namespace App\Services\Notifications;

use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    public static function send(
        string $templateKey,
        string $to,
        array $placeholders = [],
        array $options = []
    ): bool {

        $template = EmailTemplate::where(
            'template_key',
            $templateKey
        )
            ->where('is_active', true)
            ->first();

        if (! $template) {

            Log::error(
                "Email template not found: {$templateKey}"
            );

            return false;
        }

        $subject = $template->subject;
        $body = $template->body;

        foreach ($placeholders as $key => $value) {

            $body = str_replace(
                '{'.$key.'}',
                $value,
                $body
            );

            $subject = str_replace(
                '{'.$key.'}',
                $value,
                $subject
            );
        }

        try {

            Mail::send(
                'emails.layouts.master',
                [
                    'content' => $body,
                    'subject' => $subject,
                ],
                function ($message) use (
                    $to,
                    $subject,
                    $options
                ) {

                    $message
                        ->to($to)
                        ->subject($subject);

                    if (! empty($options['cc'])) {
                        $message->cc($options['cc']);
                    }

                    if (! empty($options['bcc'])) {
                        $message->bcc($options['bcc']);
                    }

                    if (! empty($options['reply_to'])) {
                        $message->replyTo(
                            $options['reply_to']
                        );
                    }

                    if (! empty($options['attachments'])) {

                        foreach (
                            $options['attachments'] as $attachment
                        ) {

                            if (
                                file_exists($attachment)
                            ) {

                                $message->attach(
                                    $attachment
                                );
                            }
                        }
                    }
                }
            );

            return true;

        } catch (\Throwable $e) {

            Log::error(
                'Email Sending Failed',
                [
                    'template' => $templateKey,
                    'recipient' => $to,
                    'error' => $e->getMessage(),
                ]
            );

            return false;
        }
    }
}
