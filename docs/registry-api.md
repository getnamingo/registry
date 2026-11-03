# Registry API

The Namingo Registry API provides read-only, JWT-authenticated access to **domain availability**, **domain droplists**, and **registrar information**. All endpoints use `GET` and return JSON.

## 1. Enable the API

Generate a secure signing secret on the control panel server:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Add the following setting to `/var/www/cp/.env`, or update it if already present:

```dotenv
REGISTRY_API_JWT_SECRET=your_generated_secret
```

Clear the control panel route cache:

```bash
php /var/www/cp/bin/clear_cache.php
```

**Keep the signing secret private.** Changing it invalidates all previously issued API tokens.

## 2. Generate an API key

Sign in to the control panel and open **Registry API Key** for the relevant registrar. Generate an API key and copy the JWT. Registry administrators can manage the key from the registrar's page. Each registrar has its own key; regenerating it immediately revokes the previous one.

Send the token in the `Authorization: Bearer` header over HTTPS.

## 3. API examples

Replace `cp.example.com` and `YOUR_JWT` with your control panel hostname and generated API key.

### Check domain availability

```bash
curl -H "Authorization: Bearer YOUR_JWT" \
  "https://cp.example.com/registry-api/availability?domain=example.test"
```

### Retrieve the domain droplist

```bash
curl -H "Authorization: Bearer YOUR_JWT" \
  "https://cp.example.com/registry-api/droplist"
```

### List registrars

```bash
curl -H "Authorization: Bearer YOUR_JWT" \
  "https://cp.example.com/registry-api/registrars"
```

## Notes

- The API is read-only; it cannot modify registry records.
- Requests without a valid token return `401 Unauthorized`.
- Rate limiting is enabled by default. Requests exceeding the configured limits return `429 Too Many Requests` with a `Retry-After` header.
- If Redis is unavailable, the API returns `503 Service Unavailable` rather than bypassing rate limiting.
- Treat JWTs as credentials and do not expose them in public repositories or logs.