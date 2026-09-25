# Patient documents API

Written for a mobile developer building against this backend. Everything here
is a tenant API endpoint under `/api/v1/tenant`.

## Before anything else

Two headers on every request:

```
X-Organization: <subdomain>          the clinic, e.g. "sunrise"
Authorization: Bearer <token>
Accept: application/json
```

`X-Organization` is not optional on mobile. The web app knows which clinic it
is from the host it was served on; a phone has no host, so the header is how
the request finds the right database — and it is read *before* the token is
looked up, because tokens live in the tenant's own database.

### Getting a token

```
POST /api/v1/tenant/auth/token
{
  "subdomain":   "sunrise",
  "email":       "doctor@clinic.in",
  "password":    "…",
  "device_name": "Pixel 8 — Dr Rao"
}
```

Returns the session payload plus `token`. One token per `device_name`, replaced
on each sign-in, so signing in again on the same phone does not accumulate
tokens. `DELETE /api/v1/tenant/auth/token` revokes only the token the request
arrived with — signing out on a phone leaves the front desk's tablet signed in.

The payload also carries `modules` and `capabilities`. **Build the UI from
those**, not from a role name: the two lists are what the server will actually
enforce, and a screen built from anything else will offer buttons that answer
403.

## What decides who sees what

Three things, in this order. The API applies all three whether or not the
client does.

1. **Module** — `documents` must be sold to the organization and running at the
   branch. If it is not, every endpoint below answers `403` and the app should
   not show the feature at all.
2. **Capability** — `documents.view`, `documents.view_clinical`,
   `documents.upload`, `documents.delete`.
3. **Category** — a document's *category* decides which of the two view
   capabilities opens it.

### The category split — read this part

Categories are either **clinical** or **administrative**:

| Sensitivity | Categories | Needs |
|---|---|---|
| clinical | `lab_report`, `imaging`, `prescription`, `discharge_summary`, `referral_letter`, `clinical_note`, `other` | `documents.view_clinical` |
| administrative | `id_proof`, `insurance`, `consent_form`, `invoice` | `documents.view` |

A caller holding only `documents.view` is **not sent clinical documents at
all** — not filtered on the client, not returned and hidden. They do not appear
in the list, they are not counted, their titles never leave the server, and
fetching one by guessing its id answers `403`. Build the list straight from
what comes back.

`other` is clinical on purpose: a document nobody categorised is one nobody has
reasoned about, and guessing "administrative" would guess in the direction that
leaks.

Do not hardcode the table above. Ask:

```
GET /document-categories
```

It returns only the categories **this caller** may file under, each with `key`,
`name`, `sensitivity`, `icon`, `tone`. A lab technician and a billing clerk get
different lists from the same endpoint.

## Endpoints

### List a patient's documents

```
GET /customers/{customer}/documents
GET /customers/{customer}/documents?category=lab_report
```

Needs `documents.view`. Newest first. Optional `category` narrows it; an
unrecognised value is ignored rather than erroring.

### List one visit's attachments

```
GET /appointments/{appointment}/documents
```

Same capability and the same narrowing. This is "the reports from today".

### Document shape

```jsonc
{
  "id": 41,
  "customer_id": 12,
  "appointment_id": 88,          // null when filed against the person only

  "title": "CBC",
  "notes": null,

  "category": "lab_report",
  "category_name": "Lab report",
  "is_clinical": true,
  "icon": "ti ti-flask",         // a hint for the list row
  "tone": "rose",

  "file_name": "report.pdf",
  "mime_type": "application/pdf",
  "file_size": 40960,            // bytes
  "extension": "pdf",

  "download_path": "/tenant/documents/41/download",

  "uploaded_by_name": "Dr Rao",
  "location_name": "Noida",

  "created_at": "2026-09-24T18:10:00+05:30",
  "updated_at": "2026-09-24T18:10:00+05:30"
}
```

**There is no URL, and there will not be one.** The bytes sit on a private disk.
A public or signed link would be a way past the capability check that does not
even leave a refused request behind it, so the file is fetched like any other
authorised call.

### Fetch the bytes

```
GET /documents/{document}/download
```

Needs `documents.view`, and `documents.view_clinical` as well when the document
is clinical — re-asked here rather than trusted from the list, because an id is
guessable. Answers the raw file with `Content-Disposition: attachment`.

On mobile: send the same two headers, take the response body as bytes, and
write it to a cache file before handing it to a viewer. Do **not** put this
path in a `WebView` or an `Intent` that will fetch it without the header — it
will come back 401 and look like the file is missing.

Answers `404` if the row exists but the stored object has gone.

### Upload

```
POST /customers/{customer}/documents      multipart/form-data
```

Needs `documents.upload`.

| Field | | |
|---|---|---|
| `file` | required | pdf, jpg, jpeg, png, webp, heic, heif, tif, tiff, doc, docx — max 20 MB |
| `category` | required | one of the keys from `GET /document-categories` |
| `appointment_id` | optional | attaches it to the visit as well as the person |
| `title` | optional | defaults to the file name |
| `notes` | optional | up to 1000 characters |

Returns `201` with the document.

**Ask for the category before opening the file picker.** What a document is
decides who may read it, so a flow that uploads first and categorises after
leaves a window in which a lab report is filed as whatever the form defaulted
to.

`appointment_id` is checked against the patient in the path. A visit belonging
to somebody else answers `422`:

```json
{ "errors": { "appointment_id": ["That visit belongs to a different patient."] } }
```

Note HEIC: iPhones shoot HEIC by default and it is accepted, but most viewers
will not render it. Converting to JPEG before upload is usually kinder to
whoever opens it on a desktop later.

### Remove

```
DELETE /documents/{document}
{ "reason": "Filed against the wrong patient" }
```

Needs `documents.delete`, and the clinical capability for a clinical document.
`reason` is required, up to 500 characters.

The removal is **soft**. The document stops appearing on the record and the row
is kept with who removed it and why — a medical document taken off a record has
to stop being readable at once and stay answerable for afterwards. The stored
file is left in place for the same reason. There is no restore endpoint yet.

## Errors

| Status | Means |
|---|---|
| 401 | No token, expired token, or the `X-Organization` header is missing |
| 403 | The module is not running here, or this capability is not held, or this document is clinical and the caller is not |
| 404 | No such document, or the stored file has gone |
| 422 | Validation — read `errors` for the field |

`403` is deliberately the same message whichever of the three it was. Telling a
caller "this document is clinical" would confirm that a clinical document with
that id exists, which is itself something they do not hold.

## Two things worth building deliberately

**Offline.** The list is small and safe to cache; the files are medical records.
Cache the metadata freely, cache the bytes only in app-private storage, and
clear both on sign-out — a phone that keeps a discharge summary after the
account is gone is the failure this whole split exists to avoid.

**Re-check on branch switch.** Capabilities are resolved per branch. If the app
lets somebody change branch, re-read `GET /auth/me` and rebuild the UI from the
new `modules` and `capabilities` — what was readable at one site may not be at
another.
