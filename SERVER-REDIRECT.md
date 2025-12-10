# Server-Side Redirect (Auto Redirect Paksa)

## 🎯 Overview

Fitur **Server-Side Redirect** memungkinkan webhook yang masuk otomatis di-forward ke URL lain **dari server** (bukan browser), sehingga **bypass CORS policy**.

### Perbedaan dengan Client-Side Redirect:

| Feature | Client-Side (XHR) | Server-Side (Guzzle) |
|---------|-------------------|----------------------|
| **Execution** | Browser | Laravel Backend |
| **CORS Issue** | ❌ Yes | ✅ No (bypass!) |
| **SSL Verification** | Strict | Configurable |
| **Headers** | Limited | Full control |
| **Authentication** | Limited | Full support |

---

## 📡 API Endpoints

### 1. Toggle Server Redirect ON/OFF

```bash
PUT /token/{tokenId}/server-redirect/toggle
```

**Response:**
```json
{
  "enabled": true
}
```

### 2. Update Server Redirect Settings

```bash
PUT /token/{tokenId}/server-redirect
Content-Type: application/json

{
  "server_redirect_url": "https://www.example.com",
  "server_redirect_method": "GET",
  "server_redirect_headers": "user-agent,accept-language,authorization",
  "server_redirect_content_type": "text/html"
}
```

**Response:**
```json
{
  "uuid": "...",
  "server_redirect_enabled": false,
  "server_redirect_url": "https://www.example.com",
  "server_redirect_method": "GET",
  "server_redirect_headers": "user-agent,accept-language,authorization",
  "server_redirect_content_type": "text/html",
  ...
}
```

---

## 🚀 Usage Examples

### Example 1: Forward ke example.com

```bash
# 1. Create token
TOKEN_ID=$(curl -s -X POST http://localhost:8084/token | jq -r '.uuid')

# 2. Configure redirect
curl -X PUT "http://localhost:8084/token/$TOKEN_ID/server-redirect" \
  -H "Content-Type: application/json" \
  -d '{
    "server_redirect_url": "https://www.example.com",
    "server_redirect_method": "GET",
    "server_redirect_headers": "user-agent,accept-language",
    "server_redirect_content_type": "text/html"
  }'

# 3. Enable redirect
curl -X PUT "http://localhost:8084/token/$TOKEN_ID/server-redirect/toggle"

# 4. Test webhook
curl "http://localhost:8084/$TOKEN_ID"

# 5. Check logs
docker logs webhook-site | grep "Server Redirect"
```

### Example 2: Forward API POST Request

```bash
# Configure for API endpoint
curl -X PUT "http://localhost:8084/token/$TOKEN_ID/server-redirect" \
  -H "Content-Type: application/json" \
  -d '{
    "server_redirect_url": "https://api.example.com/webhook",
    "server_redirect_method": "POST",
    "server_redirect_headers": "authorization,x-api-key",
    "server_redirect_content_type": "application/json"
  }'

# Enable
curl -X PUT "http://localhost:8084/token/$TOKEN_ID/server-redirect/toggle"

# Send webhook with data
curl -X POST "http://localhost:8084/$TOKEN_ID" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer abc123" \
  -d '{"event":"user.created","user_id":123}'

# Request akan di-forward ke https://api.example.com/webhook
```

### Example 3: Forward with Path Preservation

```bash
# Webhook ke: http://localhost:8084/{tokenId}/users/123?action=update
# Akan di-forward ke: https://api.example.com/users/123?action=update

curl "http://localhost:8084/$TOKEN_ID/users/123?action=update"
```

---

## ⚙️ Configuration Options

### `server_redirect_url` (required)
Base URL tujuan. Path dan query string dari request original akan di-append.

**Example:**
```
server_redirect_url: https://api.example.com
Request: /token-id/users/123?page=1
Forward to: https://api.example.com/users/123?page=1
```

### `server_redirect_method`
HTTP method untuk forward request.

**Options:**
- `default` - Use same method as incoming request
- `GET`, `POST`, `PUT`, `PATCH`, `DELETE` - Force specific method

### `server_redirect_headers`
Comma-separated list of headers to forward from original request.

**Example:**
```
"user-agent,authorization,x-api-key,content-type"
```

### `server_redirect_content_type`
Content-Type header untuk forward request.

**Example:**
- `application/json`
- `text/html`
- `application/xml`
- `text/plain`

---

## 🔍 Monitoring & Debugging

### View Logs

```bash
# All logs
docker logs webhook-site -f

# Filter server redirect logs
docker logs webhook-site | grep "Server Redirect"

# Success logs
docker logs webhook-site | grep "Forwarded request"

# Error logs
docker logs webhook-site | grep "Failed to forward"
```

### Log Format

**Success:**
```
[2024-12-10 10:30:45] local.INFO: [Server Redirect] Forwarded request 
{
  "token_id": "abc-123-def",
  "target_url": "https://www.example.com",
  "method": "GET",
  "status": 200
}
```

**Error:**
```
[2024-12-10 10:30:45] local.ERROR: [Server Redirect] Failed to forward request
{
  "token_id": "abc-123-def",
  "target_url": "https://invalid-url.com",
  "error": "cURL error 6: Could not resolve host"
}
```

---

## 🎯 Use Cases

### 1. Testing Production Webhooks
```
External Service → Webhook.site → Your Production API
                       ↓
                   Inspect & Log (no changes to production)
```

### 2. Local Development
```
Webhook Provider → Webhook.site (public) → Your localhost:3000
                                              (bypasses firewall!)
```

### 3. Webhook Transformation
```
POST /webhook → Webhook.site → PUT /api/update
(Transform method)
```

### 4. Multi-endpoint Distribution
```
                   → API Server 1
Webhook → Site →  → API Server 2
                   → API Server 3
(Extend logic to forward to multiple URLs)
```

---

## ⚠️ Important Notes

### Security
- SSL verification is disabled by default for testing
- For production, enable SSL verification in code
- Use HTTPS URLs when possible

### Performance
- Forward happens **synchronously** in same request
- Max timeout: 30 seconds
- Consider making it async for better performance

### Error Handling
- Forward errors are **logged** but **don't affect** webhook response
- Original request is always saved regardless of forward success
- Client receives response based on token settings

---

## 🔧 Troubleshooting

### Problem: Forward not happening

**Check:**
1. Is `server_redirect_enabled` set to `true`?
2. Is `server_redirect_url` configured?
3. Check Docker logs for errors

```bash
# Verify settings
curl http://localhost:8084/token/{TOKEN_ID} | jq '.server_redirect_enabled'

# Check logs
docker logs webhook-site -f | grep "Server Redirect"
```

### Problem: Connection timeout

**Solutions:**
- Increase timeout in `RequestController::forwardRequest()`
- Check target URL is accessible from container
- Verify network connectivity

### Problem: SSL certificate errors

**Solution:**
For self-signed certificates:
```php
// In RequestController.php, update client config:
$client = new Client([
    'timeout' => 30,
    'verify' => false, // Disable SSL verification
]);
```

---

## 🚀 Future Enhancements

Potential improvements:

1. **Async Forwarding**: Use Laravel Queue for non-blocking
2. **Retry Logic**: Auto-retry failed forwards
3. **Multiple URLs**: Forward to multiple endpoints
4. **Response Storage**: Save forward responses
5. **Transformation**: Custom request/response transformation
6. **Rate Limiting**: Prevent abuse
7. **Authentication**: Built-in auth mechanisms

---

## 📄 License

Same as main Webhook.site project (MIT License)

---

## 👨‍💻 Contributors

- Auto Redirect Paksa Feature added by: [Your Name]
- Date: December 2024

