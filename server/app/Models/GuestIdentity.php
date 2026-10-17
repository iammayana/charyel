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
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'guest_uuid',
        'secret_hash',
        'claimed_by_user_id',
        'claimed_at',
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
}
