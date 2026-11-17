<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice the assistant has prepared. Nothing is created until the person confirms it.
 */
class AssistantInvoiceDraft extends Model
{
    use BelongsToBusiness;

    public const PENDING = 'pending';

    public const CREATED = 'created';

    public const CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'data',
        'status',
        'reported',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'reported' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<AssistantConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
