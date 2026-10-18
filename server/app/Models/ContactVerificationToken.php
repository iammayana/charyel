<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactVerificationToken extends Model
{
    use HasFactory;

    protected $table = 'contact_verification_tokens';

    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'challenge_id',
        'guest_identity_id',
        'user_id',
        'contact_type',
        'contact_value',
        'purpose',
        'token_digest',
        'key_id',
        'attempts',
        'max_attempts',
        'expires_at',
        'verified_at',
        'locked_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'locked_at' => 'datetime',
            'created_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    /**
     * The guest identity owning this challenge, if issued for a guest principal.
     */
    public function guestIdentity(): BelongsTo
    {
        return $this->belongsTo(GuestIdentity::class, 'guest_identity_id');
    }

    /**
     * The registered user owning this challenge, if issued for a registered user principal.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Determine whether the verification challenge has expired.
     */
    public function isExpired(): bool
    {
        return now()->greaterThan($this->expires_at);
    }

    /**
     * Determine whether the verification challenge has been consumed.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Determine whether the challenge is locked due to revocation or maximum attempts exceeded.
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null || $this->attempts >= $this->max_attempts;
    }
}
