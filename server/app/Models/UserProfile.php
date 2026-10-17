<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use HasFactory;

    protected $table = 'user_profiles';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';

    /**
     * Invariant: Schema only contains updated_at, no created_at column.
     */
    public const CREATED_AT = null;

    protected $fillable = [
        'user_id',
        'locality_id',
        'primary_church_id',
        'pilgrim_name',
        'avatar_seed',
        'total_xp',
        'current_streak',
        'longest_streak',
        'is_leaderboard_public',
        'show_church_affiliation',
    ];

    protected function casts(): array
    {
        return [
            'total_xp' => 'integer',
            'current_streak' => 'integer',
            'longest_streak' => 'integer',
            'is_leaderboard_public' => 'boolean',
            'show_church_affiliation' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'primary_church_id');
    }

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class, 'locality_id');
    }
}
