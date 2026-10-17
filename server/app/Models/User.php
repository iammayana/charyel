<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The pilgrim profile associated with this user.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class, 'user_id');
    }

    /**
     * All church memberships for this user.
     */
    public function churchMemberships(): HasMany
    {
        return $this->hasMany(ChurchMembership::class, 'user_id');
    }

    /**
     * The active primary church membership for this user.
     */
    public function primaryChurchMembership(): HasOne
    {
        return $this->hasOne(ChurchMembership::class, 'user_id')
            ->where('is_primary', true)
            ->where('status', 'active');
    }

    /**
     * All guest identities claimed by this user.
     */
    public function claimedGuestIdentities(): HasMany
    {
        return $this->hasMany(GuestIdentity::class, 'claimed_by_user_id');
    }
}
