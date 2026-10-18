<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'questions';

    /**
     * Primary key is a UUIDv7 string.
     */
    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'testament',
        'book',
        'chapter',
        'verse',
        'scripture_reference',
        'scripture_version',
        'category',
        'difficulty',
        'version',
        'current_revision_id',
        'is_published',
        'is_withdrawn',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chapter' => 'integer',
            'verse' => 'integer',
            'version' => 'integer',
            'current_revision_id' => 'integer',
            'is_published' => 'boolean',
            'is_withdrawn' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * The active revision for this question.
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(QuestionRevision::class, 'current_revision_id');
    }

    /**
     * All revisions for this question in chronological order.
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(QuestionRevision::class, 'question_id')->orderBy('version', 'asc');
    }

    /**
     * Scope to only query active, published questions that have an assigned current revision.
     */
    public function scopePublished($query)
    {
        return $query->where('is_published', true)
            ->where('is_withdrawn', false)
            ->whereNotNull('current_revision_id');
    }

    /**
     * Scope by category if specified.
     */
    public function scopeCategory($query, ?string $category)
    {
        if ($category !== null && $category !== '') {
            return $query->where('category', $category);
        }
        return $query;
    }

    /**
     * Scope by difficulty if specified.
     */
    public function scopeDifficulty($query, ?string $difficulty)
    {
        if ($difficulty !== null && $difficulty !== '') {
            return $query->where('difficulty', $difficulty);
        }
        return $query;
    }

    /**
     * Scope by testament if specified ('OT', 'NT', 'BOTH').
     */
    public function scopeTestament($query, ?string $testament)
    {
        if ($testament !== null && $testament !== '') {
            return $query->where('testament', $testament);
        }
        return $query;
    }
}
