<?php

namespace App\Services;

use App\Models\ContactMediaGroup;
use App\Models\CSVData;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MentionContextService
{
    public const CONTACT_MEDIA_GROUP = 'contact_media_group';
    public const CONTACT = 'contact';

    /** Permission that governs "can access this context" per context type. */
    private const CONTEXT_PERMISSION = [
        self::CONTACT_MEDIA_GROUP => 'contact_media.view',
        self::CONTACT => 'contacts.view',
    ];

    /**
     * Context types that can own a mention thread via POST/GET /mentions/messages.
     * The per-folder thread is deprecated in favour of sticky notes but stays
     * readable for one release.
     */
    public function supportedMessageTypes(): array
    {
        return [self::CONTACT_MEDIA_GROUP];
    }

    /**
     * Context types the mention user-search endpoint accepts. Sticky notes reuse
     * this endpoint with context_type=contact because a note may not exist yet
     * when the mention picker is opened.
     */
    public function supportedUserSearchTypes(): array
    {
        return [self::CONTACT_MEDIA_GROUP, self::CONTACT];
    }

    /** @deprecated Kept for callers that predate the split; equals message types. */
    public function supportedTypes(): array
    {
        return $this->supportedMessageTypes();
    }

    public function resolve(string $type, int $id): Model
    {
        if ($type === self::CONTACT_MEDIA_GROUP) {
            $group = ContactMediaGroup::with('contact')->find($id);
            if (! $group || ! $group->contact) {
                throw new NotFoundHttpException('The requested context resource was not found.');
            }

            return $group;
        }

        if ($type === self::CONTACT) {
            $contact = CSVData::withTrashed()->find($id);
            if (! $contact) {
                throw new NotFoundHttpException('The requested context resource was not found.');
            }

            return $contact;
        }

        throw new \InvalidArgumentException('Unsupported mention context type.');
    }

    public function userCanAccess(User $user, string $type): bool
    {
        $permission = self::CONTEXT_PERMISSION[$type] ?? null;

        return $permission !== null && $user->hasPermission($permission);
    }

    public function eligibleUsersQuery(User $actor, string $type): Builder
    {
        $permission = self::CONTEXT_PERMISSION[$type] ?? null;
        if ($permission === null) {
            throw new \InvalidArgumentException('Unsupported mention context type.');
        }

        return User::query()
            ->where('is_banned', false)
            ->where(function (Builder $query) use ($permission) {
                $query->whereHas('role', function (Builder $role) use ($permission) {
                    $role->where('is_super_admin', true)
                        ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', $permission));
                })->orWhere(function (Builder $legacy) {
                    $legacy->whereNull('role_id')->whereIn('access_type', ['R', 'RW', 'RWE', 'RWED', 'FULL']);
                });
            })
            ->where(function (Builder $query) use ($actor) {
                $tenantId = $actor->microsoft_tenant_id;
                $tenantId === null
                    ? $query->whereNull('microsoft_tenant_id')
                    : $query->where('microsoft_tenant_id', $tenantId);
            });
    }

    public function frontendUrl(ContactMediaGroup $group, int $messageId): string
    {
        return rtrim((string) config('mentions.frontend_url'), '/')
            .'/contacts/'.$group->contact_id
            .'?'.http_build_query(['media_group' => $group->id, 'message' => $messageId]);
    }
}
