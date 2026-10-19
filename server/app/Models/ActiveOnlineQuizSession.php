<?php

namespace App\Models;

use App\Services\Quiz\Dto\AuthorizedQuestionItem;
use App\Services\Quiz\Dto\AuthorizedQuestionManifest;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ActiveOnlineQuizSession extends Model
{
    use HasFactory;

    protected $table = 'active_online_quiz_sessions';

    public $incrementing = false;
    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::creating(function (ActiveOnlineQuizSession $session) {
            if (empty($session->started_at)) {
                $session->started_at = now();
            }
        });
    }

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABANDONED = 'abandoned';
    public const STATUS_EXPIRED = 'expired';

    public const DIFFICULTY_EASY = 'easy';
    public const DIFFICULTY_MEDIUM = 'medium';
    public const DIFFICULTY_HARD = 'hard';
    public const DIFFICULTY_MIXED = 'mixed';

    public const ALLOWED_DIFFICULTIES = [
        self::DIFFICULTY_EASY,
        self::DIFFICULTY_MEDIUM,
        self::DIFFICULTY_HARD,
        self::DIFFICULTY_MIXED,
    ];

    public const QUIZ_MODE_STANDARD = 'standard';
    public const QUIZ_MODE_BLITZ = 'blitz';
    public const QUIZ_MODE_CHURCH_CLASH = 'church_clash';
    public const QUIZ_MODE_MARATHON = 'marathon';

    public const ALLOWED_QUIZ_MODES = [
        self::QUIZ_MODE_STANDARD,
        self::QUIZ_MODE_BLITZ,
        self::QUIZ_MODE_CHURCH_CLASH,
        self::QUIZ_MODE_MARATHON,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'guest_identity_id',
        'quiz_mode',
        'category',
        'difficulty',
        'device_id',
        'manifest_payload',
        'manifest_hash',
        'status',
        'started_at',
        'expires_at',
        'completed_at',
        'abandoned_at',
        'expired_at',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manifest_payload' => 'array',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'expired_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The guest identity owning this online session.
     */
    public function guestIdentity(): BelongsTo
    {
        return $this->belongsTo(GuestIdentity::class, 'guest_identity_id');
    }

    /**
     * Scope to only query active sessions.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Check if the session is currently active and unexpired.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && now()->lessThanOrEqualTo($this->expires_at);
    }

    /**
     * Check if the session has passed its expiration window.
     */
    public function hasExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED || now()->greaterThan($this->expires_at);
    }

    /**
     * Check if the session is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_ABANDONED, self::STATUS_EXPIRED], true);
    }

    /**
     * Reconstruct the immutable AuthorizedQuestionManifest from the persisted manifest payload.
     * Re-binds the immutable QuestionRevision records from the database.
     *
     * @throws RuntimeException If the manifest payload is invalid or revisions cannot be resolved
     */
    public function toAuthorizedManifest(): AuthorizedQuestionManifest
    {
        $payload = $this->manifest_payload;
        if (!is_array($payload) || empty($payload['items']) || !is_array($payload['items'])) {
            throw new RuntimeException("Session manifest payload is missing or invalid.");
        }

        $revisionIds = array_column($payload['items'], 'revision_id');
        $revisions = QuestionRevision::whereIn('id', $revisionIds)->get()->keyBy('id');

        $items = [];
        foreach ($payload['items'] as $rawItem) {
            $revisionId = (int) ($rawItem['revision_id'] ?? 0);
            $revision = $revisions->get($revisionId);

            $items[] = new AuthorizedQuestionItem(
                questionId: (string) ($rawItem['question_id'] ?? ''),
                revisionId: $revisionId,
                version: (int) ($rawItem['version'] ?? 1),
                difficulty: (string) ($rawItem['difficulty'] ?? self::DIFFICULTY_MEDIUM),
                category: $rawItem['category'] ?? null,
                revision: $revision
            );
        }

        return new AuthorizedQuestionManifest(
            manifestId: (string) ($payload['manifest_id'] ?? $this->id),
            quizMode: (string) ($payload['quiz_mode'] ?? $this->quiz_mode),
            difficulty: (string) ($payload['difficulty'] ?? $this->difficulty),
            category: $payload['category'] ?? $this->category,
            items: $items
        );
    }
}
