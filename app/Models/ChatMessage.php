<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = ['chat_id', 'role', 'content', 'tool_calls', 'tool_call_id', 'name'];

    protected $casts = ['tool_calls' => 'array'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /** Tvar, v akom správu očakáva rozhranie modelu. */
    public function toApi(): array
    {
        $m = ['role' => $this->role];

        if ($this->content !== null) {
            $m['content'] = $this->content;
        }
        if ($this->tool_calls) {
            $m['tool_calls'] = $this->tool_calls;
            $m['content'] ??= null;
        }
        if ($this->tool_call_id) {
            $m['tool_call_id'] = $this->tool_call_id;
        }

        return $m;
    }
}
