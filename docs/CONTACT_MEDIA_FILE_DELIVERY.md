# Contact Media – File Delivery

Files in contact document folders (`contact_media_groups` / `contact_media_files`)
are delivered two ways. Raw Cloudinary URLs (`file_url`, `cloudinary_public_id`)
are never returned by the API.

## 1. `preview_url` (images only)

Every `contact_media_files` payload now includes:

- `preview_url` — a **signed Cloudinary delivery URL**, present only when
  `resource_type === "image"` and the parent group is not sensitive
  (`sensitivity === "normal"`). `null` otherwise. Use it for grid thumbnails and
  inline image previews.
- `download_url` — the relative streaming path (below), present for
  non-sensitive groups, `null` for sensitive/restricted document groups.

Signed URLs work for the current public (`type=upload`) assets and keep working
if the account later enforces strict delivery. The Cloudinary account owner
should still enable **Settings → Security → "Allow delivery of PDF and ZIP
files"**.

## 2. Streaming download / preview route

```
GET /api/contacts/{contact}/media-groups/{group}/files/{file}/download
GET /api/contacts/{contact}/media-groups/{group}/files/{file}/download?disposition=inline
```

- Permission: `contact_media.view`. Throttled `60/min`.
- The app server fetches the asset from Cloudinary server-to-server and streams
  it with the correct `Content-Type` and `Content-Disposition`.
  `disposition` defaults to `attachment`; pass `inline` for the in-app viewer
  (PDF `<iframe>`, `<video>`, client-side office parsing).
- Sensitive/restricted document groups return `404` here — those are served only
  through the audited `/api/contacts/{contact}/documents/{file}/download`
  endpoint. Purged assets return `410`.
