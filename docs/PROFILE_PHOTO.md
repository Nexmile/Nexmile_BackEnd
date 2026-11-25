# Profile photo

The customer's own profile picture. This did not exist before — there was no
column, no endpoint and no field in the response — so if the app was binding
to something, it was binding to a key that was never sent.

Two endpoints, both on the account the token belongs to. There is no user id
in either path: you can only change your own.

## Reading it

`avatar_url` is now on **every** response that returns an account:

- `GET /v1/profile`
- `PATCH /v1/profile`
- `POST /v1/auth/otp/verify` (under `data.user`)
- `POST /v1/merchant/login` (under `data.user`)

```json
{
  "data": {
    "id": 412,
    "name": "Lakshmi",
    "phone": "9780000501",
    "preferred_locale": "ta",
    "avatar_url": "https://...signed...",
    "..."
  }
}
```

**`avatar_url` is `null` when no picture is set.** The key is always present,
so bind to it directly and draw initials on null. It is never absent.

**The URL is signed and expires after 24 hours.** Cache the image, not the
URL. If you hold a URL for a day and it starts 403-ing, re-read the profile
rather than treating it as a broken image.

## Setting it

```
POST /v1/profile/photo
Content-Type: multipart/form-data
```

| field   | type | notes                              |
|---------|------|------------------------------------|
| `photo` | file | required. JPG, PNG or WebP. ≤ 4 MB |

`200` with the **whole profile**, same shape as `GET /v1/profile`, so you can
rebind the account screen from this one response without a second call:

```json
{ "message": "Profile photo saved.", "data": { "...": "...", "avatar_url": "https://..." } }
```

Posting again replaces the picture. There is no separate "update" — the old
file is deleted once the new one is committed.

### Refusals

`422` under `errors.photo`:

- not an image, or an SVG — `Upload a JPG, PNG or WebP photo.`
- over 4 MB — `Photos must be under 4 MB.`
- no file sent — the usual required message

**These three messages are customer-safe and can be shown as they are.**

`401` when the token is missing or expired.

## Removing it

```
DELETE /v1/profile/photo
```

`200` with the profile and `avatar_url: null`. The stored file is deleted too,
not just the pointer to it.

**Calling this when there is no picture is a `200`, not an error** — so you can
wire it to a menu item without first checking the state.

## Things worth knowing

**Send the original. Do not resize or crop before uploading.** The server
re-encodes to 256px WebP at quality 82, which is twice the size the largest
avatar is drawn at. A 3 MB selfie lands as roughly 15 KB. Compressing on the
phone first only costs quality — you would be throwing away pixels, then we
throw away more.

**Do crop to a square if your UI does.** The server scales the longest edge
down and does not crop, so a portrait photo stays portrait and your circle
will centre-crop it on display. If you want the user to choose what is inside
the circle, do that in the picker and send the square.

**There is no rider or merchant avatar, and `rider.photo_url` is still
`null`.** That is not an oversight and it is not this endpoint. Riders upload
a `profile_photo` as part of KYC, but that is an identity document stored
beside their Aadhaar and bank statements — it was collected to verify who
they are, not to be shown to customers. Showing it as an avatar would be
handing out a person's ID photo. If we want rider faces on the tracking
screen, that is a separate consented photo and a product decision, not a
field to switch on.

**The avatar is only ever returned to its own owner.** It is not in
`OrderResource`, `ReviewResource` or the support chat, so do not expect to
draw a customer's face anywhere a merchant or a rider can see.
