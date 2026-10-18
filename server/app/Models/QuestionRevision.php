<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionRevision extends Model
{
    use HasFactory;

    protected $table = 'question_revisions';

    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'question_id',
        'version',
        'question_text',
        'options',
        'correct_option_id',
        'explanation',
        'change_summary',
        'created_by_user_id',
        'created_at',
    ];

    /**
     * The attributes that should be hidden for standard serialization to prevent leaking answers.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'correct_option_id',
        'explanation',
        'change_summary',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'options' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The parent question.
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    /**
     * The author who published/created this revision, if tracked.
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Verify whether an option identifier exists in this revision's options.
     */
    public function hasOption(string $optionId): bool
    {
        if (!is_array($this->options)) {
            return false;
        }

        foreach ($this->options as $option) {
            if (isset($option['id']) && (string) $option['id'] === $optionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate if a submitted option identifier is the correct answer.
     */
    public function isCorrect(string $optionId): bool
    {
        return trim((string) $this->correct_option_id) === trim($optionId);
    }

    /**
     * Extract the display text for a given option ID.
     */
    public function getOptionText(string $optionId): ?string
    {
        if (!is_array($this->options)) {
            return null;
        }

        foreach ($this->options as $option) {
            if (isset($option['id']) && (string) $option['id'] === $optionId) {
                return (string) ($option['text'] ?? '');
            }
        }

        return null;
    }

    /**
     * Returns a strictly sanitized, client-safe array of question data.
     * Guaranteed to NEVER expose correct_option_id, explanation, or internal audit fields.
     */
    public function toClientSafeArray(): array
    {
        $sanitizedOptions = [];
        if (is_array($this->options)) {
            foreach ($this->options as $option) {
                if (isset($option['id'], $option['text'])) {
                    $sanitizedOptions[] = [
                        'id' => (string) $option['id'],
                        'text' => (string) $option['text'],
                    ];
                }
            }
        }

        return [
            'question_id' => (string) $this->question_id,
            'version' => (int) $this->version,
            'question_text' => (string) $this->question_text,
            'options' => $sanitizedOptions,
            'category' => $this->question?->category,
            'difficulty' => $this->question?->difficulty,
            'scripture_reference' => $this->question?->scripture_reference,
            'scripture_version' => $this->question?->scripture_version ?? 'KJV',
        ];
    }
}
