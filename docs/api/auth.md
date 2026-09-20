# Auth

## `GET /api/health`

No auth. Liveness check.

**200**
```json
{ "status": "ok" }
```

---

## `POST /api/v1/auth/login`

No auth. Rate-limited: 5 attempts/minute per `email+IP` pair, then `429`.

**Request**
```json
{ "email": "ahmed@vetpharma.com", "password": "password" }
```

**200**
```json
{
  "token": "1|abcdef123456...",
  "user": {
    "id": "01m2r4qkj97hj2bjqr6w0vzwms",
    "name": "Ahmed Hassan",
    "email": "ahmed@vetpharma.com",
    "role": "Sales Manager",
    "permissions": ["sales.view", "sales.add", "sales.edit", "sales.approve", "sales.print", "sales.export"],
    "avatar": "AH",
    "status": "active"
  }
}
```

Store `token` and send it as `Authorization: Bearer <token>` on every subsequent
request. It does not expire; it is invalidated only by `POST /auth/logout`.

**422** — wrong email/password (deliberately the same message for both, to avoid
revealing which one was wrong):
```json
{ "errors": { "email": ["These credentials do not match our records."] } }
```

**422** — account not active (`status` is `inactive` or `suspended`):
```json
{ "errors": { "email": ["This account is not active."] } }
```

**429** — too many attempts.

---

## `POST /api/v1/auth/logout`

Auth required. Revokes the token used to make this request (real revocation —
the same token will get `401` on any further request, immediately).

**200**
```json
{ "message": "Logged out." }
```

---

## `GET /api/v1/auth/me`

Auth required. Returns the current user, same shape as the `user` object from
login.

**200**
```json
{
  "data": {
    "id": "01m2r4qkj97hj2bjqr6w0vzwms",
    "name": "Ahmed Hassan",
    "email": "ahmed@vetpharma.com",
    "role": "Sales Manager",
    "permissions": ["sales.view", "sales.add", "sales.edit", "sales.approve", "sales.print", "sales.export"],
    "avatar": "AH",
    "status": "active"
  }
}
```

---

## `POST /api/v1/auth/change-password`

Auth required.

**Request**
```json
{
  "current_password": "password",
  "password": "new-password",
  "password_confirmation": "new-password"
}
```

**200**
```json
{ "message": "Password changed." }
```

**422** — wrong current password:
```json
{ "errors": { "current_password": ["The password is incorrect."] } }
```

**422** — `password`/`password_confirmation` mismatch or too weak:
```json
{ "errors": { "password": ["The password field confirmation does not match."] } }
```

The old token keeps working after a password change (it is not auto-revoked).
