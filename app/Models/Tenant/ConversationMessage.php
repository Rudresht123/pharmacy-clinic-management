<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing said in a conversation: text, a file, or both.
 *
 * Never edited and never deleted on its own — a clinical exchange has to read
 * afterwards exactly as it was sent.
 */
class ConversationMessage extends Model
{
    protected $connection = 'organization';

    protected $fillable = [
        'conversation_id',
        'sender_side',
        'sender_user_id',
        'body',
        'file_id',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id')->withTrashed();
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
