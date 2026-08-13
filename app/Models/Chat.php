<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    protected $fillable = ['user_id', 'title', 'last_message_at'];

    protected $casts = ['last_message_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    /** Názov chatu z prvej otázky — nech sa dá v zozname nájsť. */
    public function titleFrom(string $question): void
    {
        if ($this->title) {
            return;
        }

        $title = trim(preg_replace('/\s+/u', ' ', $question));
        $this->update(['title' => mb_strimwidth($title, 0, 80, '…')]);
    }
}
