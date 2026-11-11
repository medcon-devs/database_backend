# Tags API

All endpoints require the JWT access token:

```http
Authorization: Bearer <access_token>
Accept: application/json
```

## Permissions

| Action | Allowed access types |
|---|---|
| View tags and their contacts | `R`, `RW`, `RWE`, `RWED`, `FULL` |
| Create, edit, or delete a tag | `FULL` |
| Add, replace, or remove tag contacts | `RWE`, `RWED`, `FULL` |

Deleting a tag removes only its relationships. It never deletes contact records.

## Tag endpoints

### List tags

```http
GET /api/tags?search=Dubai&perPage=30&page=1
```

Each tag includes `contacts_count` so the frontend can display cards such as
`Dubai Event — 45 contacts`.

### Create a tag

```http
POST /api/tags
```

```json
{
  "name": "Dubai Event 2026",
  "color": "#2563EB",
  "description": "Contacts invited to the Dubai event."
}
```

`name` must be unique and is limited to 100 characters. `color` must be a
six-digit hexadecimal color. The backend creates the unique `slug`.

### Get one tag

```http
GET /api/tags/5
```

### Update a tag

```http
PATCH /api/tags/5
```

Send only fields that changed:

```json
{
  "name": "Dubai Congress 2026",
  "color": "#DC2626"
}
```

### Delete a tag

```http
DELETE /api/tags/5
```

The response includes `detached_contacts`. Contact records remain unchanged.

## Contact membership endpoints

### List contacts inside a tag

```http
GET /api/tags/5/contacts?search=Ali&countries[]=United%20Arab%20Emirates&specialities[]=Cardiology&perPage=30&page=1
```

The endpoint supports:

- Text search across first name, last name, email, phone, country, and speciality.
- Multiple unified country filters through `countries[]`. For example, `United Arab Emirates` also matches historical values such as `UAE`, `Dubai`, and `Abu Dhabi`.
- Multiple unified speciality filters through either `specialities[]` or `specialties[]`.
- Pagination up to 100 contacts per page.

### List contacts available to add

```http
GET /api/tags/5/available-contacts?search=Ali&perPage=30&page=1
```

This has the same filters but excludes contacts already assigned to the tag.

### Add contacts without removing existing contacts

```http
POST /api/tags/5/contacts
```

```json
{
  "contact_ids": [20, 35, 47]
}
```

This is the recommended endpoint for the frontend's **Add contacts to tag**
dialog. Contacts already inside the tag are ignored safely.

### Replace the complete contact selection

```http
PUT /api/tags/5/contacts
```

```json
{
  "contact_ids": [20, 47]
}
```

This makes the submitted IDs the complete membership list. Sending an empty
array removes every contact from the tag. Use this only when the frontend has
the complete selection, not only one page of paginated results.

### Remove selected contacts

```http
DELETE /api/tags/5/contacts
```

```json
{
  "contact_ids": [35, 47]
}
```

This removes only the relationships. The contacts and tag remain in the database.

## Export by included and excluded tags

```http
POST /api/export-data
```

Use `include_tag_ids` to export contacts belonging to **any** selected tag. Use
`exclude_tag_ids` to remove every contact belonging to **any** excluded tag.
Exclusion always wins.

```json
{
  "include_tag_ids": [10, 11],
  "exclude_tag_ids": [12],
  "countries": ["United Arab Emirates"],
  "specialities": ["Cardiology"],
  "text": ""
}
```

The operation is:

```text
(contacts in any included tag) - (contacts in any excluded tag)
```

For example, if X contains `u1,u2,u3,u4`, Y contains `u2,u5,u6`, and Z contains
`u1,u2`, including X and Y while excluding Z exports `u3,u4,u5,u6`. Duplicate
contacts are exported once.

When `include_tag_ids` is empty or omitted, the existing search, country, and
speciality result is used as the starting set. Excluded tags are then removed
from it. Both tag arrays accept up to 100 existing tag IDs.

## Pagination response

List endpoints use this common shape:

```json
{
  "data": [],
  "status_code": 200,
  "message": "Tags retrieved successfully.",
  "status": true,
  "perPage": 30,
  "currentPage": 1,
  "total": 45,
  "totalPages": 2,
  "is_next": true
}
```
