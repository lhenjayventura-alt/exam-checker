<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /** Per-request cache of role assignments. */
    private ?array $roleAssignments = null;

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
            'is_active' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withPivot('program_id')->withTimestamps();
    }

    public function teachingOfferings(): BelongsToMany
    {
        return $this->belongsToMany(CourseOffering::class, 'teaching_assignments')->withTimestamps();
    }

    /** @return list<array{slug: string, program_id: ?int}> */
    private function roleAssignments(): array
    {
        return $this->roleAssignments ??= $this->roles()->get()
            ->map(fn (Role $r) => ['slug' => $r->slug, 'program_id' => $r->pivot->program_id])
            ->all();
    }

    public function hasRole(string $slug): bool
    {
        return collect($this->roleAssignments())->contains('slug', $slug);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::ADMIN);
    }

    public function isTeacher(): bool
    {
        return $this->hasRole(Role::TEACHER);
    }

    public function isProgramChair(): bool
    {
        return $this->hasRole(Role::PROGRAM_CHAIR);
    }

    /** @return list<int> */
    public function chairedProgramIds(): array
    {
        return collect($this->roleAssignments())
            ->where('slug', Role::PROGRAM_CHAIR)
            ->pluck('program_id')->filter()->map(fn ($id) => (int) $id)->values()->all();
    }

    public function forgetRoleCache(): void
    {
        $this->roleAssignments = null;
    }
}
