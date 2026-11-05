<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\EmployeeAccess;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    protected $fillable = ['name', 'email', 'password', 'role', 'is_active'];

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
            'password' => 'hashed', 'is_active' => 'boolean',
            'must_change_password' => 'boolean', 'session_version' => 'integer',
        ];
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function hasRole(string ...$roles): bool
    {
        $access = app(EmployeeAccess::class);

        return (in_array('admin', $roles, true) && $access->admin($this))
            || (in_array('hr_manager', $roles, true) && $access->hr($this))
            || (in_array('employee', $roles, true) && $access->enabled($this) && ! $access->admin($this));
    }
}
