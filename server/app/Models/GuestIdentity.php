<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class GuestIdentity extends Model
{
    use HasApiTokens, HasFactory;

    protected $table = 'guest_identities';

    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     * Note: 'is_legacy' is deliberately excluded to prevent client injection.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'guest_uuid',
        'secret_hash',
        'claimed_by_user_id',
        'claimed_at',
        'email',
        'phone_number',
        'email_verified_at',
        'phone_verified_at',
        'completed_quiz_count',
        'gross_lifetime_xp',
        'active_quiz_session_id',
        'trial_limit_reached_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'secret_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'created_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'trial_limit_reached_at' => 'datetime',
            'is_legacy' => 'boolean',
            'completed_quiz_count' => 'integer',
            'gross_lifetime_xp' => 'integer',
        ];
    }

    /**
     * The registered user who claimed this guest identity, if any.
     */
    public function claimedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    /**
     * Check if this guest identity has been claimed by a registered user.
     */
    public function isClaimed(): bool
    {
        return $this->claimed_at !== null || $this->claimed_by_user_id !== null;
    }

    /**
     * Determine if this guest identity has at least one contact channel attached.
     */
    public function hasContact(): bool
    {
        return !empty($this->email) || !empty($this->phone_number);
    }

    /**
     * Check if the email address has been verified.
     */
    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Check if the phone number has been verified.
     */
    public function isPhoneVerified(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Clean and normalize an input phone number to canonical E.164.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $trimmed = trim($phone);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }
}
