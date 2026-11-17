<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat between one person and the in-app assistant, within one business.
 */
class AssistantConversation extends Model
{
    use BelongsToBusiness;

    /**
     * Conversations stop here so a single chat can't grow without end.
     */
    public const MAX_QUESTIONS = 40;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'transcript',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'transcript',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transcript' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AssistantMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class)->orderBy('id');
    }

    /**
     * @return HasMany<AssistantInvoiceDraft, $this>
     */
    public function drafts(): HasMany
    {
        return $this->hasMany(AssistantInvoiceDraft::class)->orderBy('id');
    }
}
