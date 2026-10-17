<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchMembership extends Model
{
    use HasFactory;

    protected $table = 'church_memberships';

    /**
     * Invariant: Schema only contains joined_at, no created_at or updated_at columns.
     */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'church_id',
        'role',
        'is_primary',
        'status',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function church(): BelongsTo
    {
        return $this->belongsTo(Church::class, 'church_id');
    }
}
