<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiKnowledgeBase extends Model
{
    protected $table = 'ai_knowledge_base';

    protected $fillable = [
        'company_id', 'name', 'description', 'document_type',
        'raw_content', 'file_path', 'source_url',
        'status', 'word_count', 'chunk_count', 'error_message',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(AiKnowledgeChunk::class, 'knowledge_base_id');
    }
}
