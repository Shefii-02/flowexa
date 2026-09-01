<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKnowledgeChunk extends Model
{
    protected $table = 'ai_knowledge_chunks';

    protected $fillable = [
        'knowledge_base_id', 'company_id', 'content',
        'chunk_index', 'tfidf_vector', 'metadata',
    ];

    protected $casts = [
        'tfidf_vector' => 'array',
        'metadata'     => 'array',
    ];

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeBase::class, 'knowledge_base_id');
    }
}
