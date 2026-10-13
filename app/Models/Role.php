<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    public const ADMIN = 'administrator';

    public const PROGRAM_CHAIR = 'program_chair';

    public const TEACHER = 'teacher';

    public const NAMES = [
        self::ADMIN => 'Administrator',
        self::PROGRAM_CHAIR => 'Program Chair',
        self::TEACHER => 'Teacher',
    ];

    protected $fillable = ['slug', 'name'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('program_id')->withTimestamps();
    }
}
