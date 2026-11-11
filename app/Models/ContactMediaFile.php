<?php

namespace App\Models;

use App\Services\CloudinaryUploadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactMediaFile extends Model
{
    use SoftDeletes;

    protected $fillable = ['group_id', 'file_url', 'cloudinary_public_id', 'file_name', 'resource_type', 'format', 'mime_type', 'file_size', 'deleted_via_group', 'asset_purged_at'];

    protected $casts = ['file_size' => 'integer', 'deleted_via_group' => 'boolean', 'asset_purged_at' => 'datetime'];

    protected $hidden = ['file_url', 'cloudinary_public_id'];

    protected $appends = ['preview_url', 'download_url'];

    private ?ContactMediaGroup $resolvedGroup = null;

    private bool $groupResolved = false;

    public function group()
    {
        return $this->belongsTo(ContactMediaGroup::class, 'group_id');
    }

    /**
     * Signed Cloudinary URL for inline rendering of image files in the folders
     * UI. Never emitted for sensitive/restricted document groups — those must be
     * fetched through the audited document download endpoint.
     */
    public function getPreviewUrlAttribute(): ?string
    {
        if ($this->resource_type !== 'image' || ! $this->isNormalGroup()) {
            return null;
        }

        return CloudinaryUploadService::signedImageUrl($this->cloudinary_public_id, $this->format);
    }

    /**
     * Relative API path that streams the file through the app server with the
     * correct Content-Type/Content-Disposition. Null for sensitive groups.
     */
    public function getDownloadUrlAttribute(): ?string
    {
        $group = $this->resolveGroup();
        if (! $group || $group->sensitivity !== 'normal') {
            return null;
        }

        return "/api/contacts/{$group->contact_id}/media-groups/{$group->id}/files/{$this->id}/download";
    }

    private function isNormalGroup(): bool
    {
        $group = $this->resolveGroup();

        return $group !== null && $group->sensitivity === 'normal';
    }

    /**
     * Resolve the parent group once per instance. Uses the eager-loaded relation
     * when present, otherwise a lightweight query. Never assigns the relation so
     * serialization stays acyclic.
     */
    private function resolveGroup(): ?ContactMediaGroup
    {
        if ($this->groupResolved) {
            return $this->resolvedGroup;
        }
        $this->groupResolved = true;

        return $this->resolvedGroup = $this->relationLoaded('group')
            ? $this->getRelation('group')
            : $this->group()->first();
    }
}
