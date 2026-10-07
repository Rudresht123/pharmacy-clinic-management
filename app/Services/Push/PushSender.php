<?php

namespace App\Services\Push;

/**
 * Delivers a push notification to device tokens — and nothing else.
 *
 * Firebase is the delivery mechanism, not a source of truth. Who receives a
 * push, and what it says, is decided in Laravel before a sender is ever
 * called; a sender only reports what happened to each token.
 */
interface PushSender
{
    /**
     * @param  list<string>  $tokens
     */
    public function send(PushMessage $message, array $tokens): PushResult;
}
