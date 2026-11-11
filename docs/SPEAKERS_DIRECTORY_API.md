# Speakers Directory API

All endpoints are under `/api`, require a bearer token, an active account, exact Microsoft tenant matching, and the listed permission.

## Profiles

- `GET /speakers` — `speakers.view`; supports `search`, `status`, `page`, `per_page`.
- `POST /speakers` — `speakers.create`.
- `GET /speakers/{speaker}` — `speakers.view`.
- `PATCH /speakers/{speaker}` — `speakers.edit`.
- `PATCH /speakers/{speaker}/status` — `speakers.archive`.

Create payload:

```json
{
  "contact_id": 123,
  "professional_title": "Professor of Cardiology",
  "biography": "Speaker biography.",
  "status": "active"
}
```

`contact_id` links the speaker to the existing contact record, which remains the source for full name, phone, email, speciality, and country. Status values are `active`, `inactive`, and `archived`.

## Bank details

- `GET /speakers/{speaker}/bank-details` — `speaker_bank_details.view`.
- `PUT /speakers/{speaker}/bank-details` — `speaker_bank_details.edit`.

```json
{
  "account_holder_name": "Speaker Name",
  "bank_name": "Example Bank",
  "account_number": "123456789",
  "iban": "AE...",
  "swift_bic": "EXAMPLE",
  "bank_country": "United Arab Emirates",
  "currency": "AED",
  "bank_address": "...",
  "beneficiary_address": "...",
  "verification_status": "pending"
}
```

Sensitive bank values are encrypted at rest. Verification values are `unverified`, `pending`, `verified`, and `rejected`.

## Documents

- `GET /speakers/{speaker}/documents` — `speaker_documents.view`; optional `category`.
- `POST /speakers/{speaker}/documents/upload-signature` — `speaker_documents.upload`.
- `POST /speakers/{speaker}/documents` — `speaker_documents.upload`.
- `GET /speakers/{speaker}/documents/{file}/download` — `speaker_documents.download`.
- `DELETE /speakers/{speaker}/documents/{file}` — `speaker_documents.delete`.
- `GET /speakers/{speaker}/download-activity` — `speaker_download_activity.view`.

Supported document categories:

- `profile_photo`
- `passport_id`
- `medcon_contract`
- `partner_contract`
- `bank_document`
- `other`

Create a document group after uploading through the issued Cloudinary signature:

```json
{
  "title": "MEDCON 2026 Contract",
  "note": "Signed contract",
  "category": "medcon_contract",
  "files": [{
    "file_url": "https://res.cloudinary.com/...",
    "cloudinary_public_id": "database/contacts/123/...",
    "file_name": "contract.pdf",
    "resource_type": "raw",
    "format": "pdf",
    "mime_type": "application/pdf",
    "file_size": 100000
  }]
}
```

Storage URLs and Cloudinary public IDs are never returned by document APIs. Files must be opened through the authenticated download endpoint. Passport downloads additionally require `speaker_identity_documents.download`. Bank-document downloads additionally require `speaker_bank_details.view`.

Every download records the user, speaker, file, category, time, and hashed request metadata. Passport and bank-document downloads queue a notification to active same-tenant users with `speaker_notifications.receive`.

## Permission list

- `speakers.view`
- `speakers.create`
- `speakers.edit`
- `speakers.archive`
- `speakers.export` (reserved for directory export)
- `speaker_documents.view`
- `speaker_documents.upload`
- `speaker_documents.download`
- `speaker_documents.delete`
- `speaker_identity_documents.download`
- `speaker_bank_details.view`
- `speaker_bank_details.edit`
- `speaker_download_activity.view`
- `speaker_notifications.receive`

These permissions appear automatically in the grouped RBAC catalog and `/api/get-user` after assignment.
