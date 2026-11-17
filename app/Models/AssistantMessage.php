<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One message in an assistant chat, as the person sees it.
 */
class AssistantMessage extends Model
{
    use BelongsToBusiness;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'role',
        'body',
        'input_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'output_tokens',
    ];

    /**
     * @return BelongsTo<AssistantConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }

    /**
     * @return HasMany<AssistantInvoiceDraft, $this>
     */
    public function drafts(): HasMany
    {
        return $this->hasMany(AssistantInvoiceDraft::class)->orderBy('id');
    }
}
