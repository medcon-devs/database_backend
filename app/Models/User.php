<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'auth_provider',
        'microsoft_tenant_id',
        'microsoft_oid',
        'last_microsoft_login_at',
        'is_banned',
        'access_type',
        'role_id',
        'receive_notification',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'microsoft_tenant_id',
        'microsoft_oid',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_banned' => 'boolean',
        'receive_notification' => 'boolean',
        'last_microsoft_login_at' => 'datetime',
    ];

    public function scopeNotificationAdministrators($query)
    {
        return $query
            ->where('access_type', 'FULL')
            ->where('receive_notification', true)
            ->whereNotNull('email_verified_at')
            ->where('is_banned', false);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function hasPermission(string $permission): bool
    {
        $role = $this->getAttribute('role_id')
            ? ($this->relationLoaded('role') ? $this->role : $this->role()->with('permissions:id,name')->first())
            : null;

        if ($role) {
            return $role->is_super_admin || $role->permissions->contains('name', $permission);
        }

        // Temporary compatibility for users/tests created before role_id is
        // populated. New and migrated production users always use a role.
        if ($this->access_type === 'FULL') {
            return true;
        }
        $legacy = [
            'R' => ['contacts.view', 'tags.view', 'contact_media.view', 'activity.view_own'],
            'RW' => ['contacts.view', 'contacts.create', 'contacts.import', 'contacts.export', 'tags.view', 'contact_media.view', 'activity.view_own'],
            'RWE' => ['contacts.view', 'contacts.create', 'contacts.import', 'contacts.export', 'contacts.edit', 'tags.view', 'tags.manage_contacts', 'contact_media.view', 'contact_media.upload', 'contact_media.edit', 'activity.view_own', 'activity.view_contact'],
            'RWED' => ['contacts.view', 'contacts.create', 'contacts.import', 'contacts.export', 'contacts.edit', 'contacts.archive', 'tags.view', 'tags.manage', 'tags.manage_contacts', 'tags.export', 'contact_media.view', 'contact_media.upload', 'contact_media.edit', 'contact_media.delete', 'contact_media.restore', 'activity.view_own', 'activity.view_contact'],
        ];

        return in_array($permission, $legacy[$this->access_type] ?? [], true);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return collect($permissions)->contains(fn (string $permission) => $this->hasPermission($permission));
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }
}
