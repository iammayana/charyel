<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Church extends Model
{
    use HasFactory;

    protected $table = 'churches';

    protected $fillable = [
        'locality_id',
        'name',
        'slug',
        'denomination',
        'is_verified',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
        ];
    }

    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class, 'locality_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ChurchMembership::class, 'church_id');
    }
}
