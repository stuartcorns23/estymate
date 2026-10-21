<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\BusinessRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
     * @return BelongsToMany<Business, $this>
     */
    public function businesses(): BelongsToMany
    {
        return $this->belongsToMany(Business::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function currentBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'current_business_id');
    }

    /**
     * The user's role in the given business, or null when they are not a member.
     */
    public function roleIn(Business $business): ?BusinessRole
    {
        $role = $this->businesses()->whereKey($business->id)->first()?->pivot->role;

        return $role ? BusinessRole::from($role) : null;
    }

    /**
     * Make the given business the one the user is working in.
     */
    public function switchBusiness(Business $business): bool
    {
        if (! $this->businesses()->whereKey($business->id)->exists()) {
            return false;
        }

        $this->forceFill(['current_business_id' => $business->id])->save();
        $this->setRelation('currentBusiness', $business);

        return true;
    }
}
