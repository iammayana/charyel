<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Locality extends Model
{
    use HasFactory;

    protected $table = 'localities';
    public $timestamps = false;

    protected $fillable = [
        'region_id',
        'name',
        'slug',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function churches(): HasMany
    {
        return $this->hasMany(Church::class, 'locality_id');
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(UserProfile::class, 'locality_id');
    }
}
