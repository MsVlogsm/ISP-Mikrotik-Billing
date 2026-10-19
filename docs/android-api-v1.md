# X-Link Billing — Android REST API v1

This API is for authenticated staff/reseller Android clients. It uses JSON over HTTPS and Laravel Sanctum personal-access tokens. Existing `/api/user` and payment webhook endpoints are left intact.

## Before shipping an APK

The current Billing listener has been observed on port `8081` using HTTP. **Do not ship a production APK that sends passwords or Bearer tokens over plain HTTP.** Configure a valid TLS/HTTPS endpoint (usually port 443 with a reverse proxy to the Laravel Billing service) first. Use the HTTPS URL as `BASE_URL` in the Android app and never disable certificate validation.

## Authentication

`POST /api/v1/auth/login`

```json
{
  "email": "staff@example.com",
  "password": "the-user-password",
  "device_name": "Pixel 8"
}
```

Success response includes `token_type: Bearer`, `access_token`, `expires_at`, and a `user` object containing roles and effective permissions. Tokens expire after 30 days by default; configure `MOBILE_API_TOKEN_EXPIRATION_DAYS` to change this. A repeat login on the same device name replaces that device's previous token.

For all authenticated calls send:

```http
Accept: application/json
Authorization: Bearer <access_token>
```

`POST /api/v1/auth/logout` revokes the current token. `GET /api/v1/me` returns the authenticated profile and permissions. Store tokens with Android Keystore-backed encrypted storage; do not log tokens or passwords.

## Endpoints

| Method | Endpoint | Purpose / access |
|---|---|---|
| POST | `/api/v1/auth/login` | Email/password login; throttled |
| POST | `/api/v1/auth/logout` | Revoke this device token |
| GET | `/api/v1/me` | Profile, roles and permissions |
| GET | `/api/v1/dashboard` | Permission-aware dashboard counters; unavailable statistics are `null` |
| GET | `/api/v1/customers?per_page=20&page=1&q=BT&status=active` | Paginated, permission-checked customer list |
| GET | `/api/v1/customers/{customerId}` | Customer detail and service/package basics |
| GET | `/api/v1/customers/{customerId}/billing` | Bill amount/due/date summary |
| GET | `/api/v1/customers/{customerId}/payments?per_page=20` | Paginated payment collection history |
| GET | `/api/v1/packages` | Published packages visible to that user/reseller |

Customer IDs in these routes are the numeric database record IDs returned by the list. Customer Unique ID/CID is returned as `customer_unique_id` for display/search, not used as a route ID.

A Reseller account can only access customers and reseller-specific plans associated with its own reseller profile. Admin/Manager actions still depend on the web application's explicit permissions. This initial API is read-oriented; it does **not** accept arbitrary amounts to record payments or change billing, and it never returns PPPoE passwords.

Subscriber self-service login is deliberately separate: customer records do not provide a verified customer password for this staff account login. A customer-facing Android app needs a proper OTP/password enrollment flow before exposing a customer's own billing data; do not authenticate subscribers using CID + mobile number alone.

## Status codes

- `200`: success
- `401`: missing/invalid/expired mobile token or invalid login credentials
- `403`: authenticated user lacks the required role/permission
- `404`: customer or billing record not found within the user's allowed scope
- `422`: invalid input (Laravel validation JSON)
- `429`: login request rate limit exceeded

## cURL smoke test

Replace `https://YOUR-TLS-BILLING-HOST` with the TLS-enabled production origin:

```bash
curl -sS -X POST 'https://YOUR-TLS-BILLING-HOST/api/v1/auth/login' \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"staff@example.com","password":"CHANGE-ME","device_name":"Android test"}'

curl -sS 'https://YOUR-TLS-BILLING-HOST/api/v1/me' \
  -H 'Accept: application/json' -H 'Authorization: Bearer YOUR_ACCESS_TOKEN'
```

## Android Retrofit sketch (Kotlin)

```kotlin
 data class LoginRequest(val email: String, val password: String, val device_name: String)
 data class LoginResponse(val token_type: String, val access_token: String, val expires_at: String)

 interface BillingApi {
     @POST("api/v1/auth/login")
     suspend fun login(@Body body: LoginRequest): LoginResponse

     @GET("api/v1/me")
     suspend fun me(): retrofit2.Response<okhttp3.ResponseBody>

     @GET("api/v1/customers")
     suspend fun customers(@Query("page") page: Int = 1, @Query("per_page") perPage: Int = 20,
                           @Query("q") query: String? = null): retrofit2.Response<okhttp3.ResponseBody>
 }
```

Attach the token in an OkHttp interceptor as `Authorization: Bearer <token>`. Parse the complete JSON response models in the app rather than exposing arbitrary response bodies in production UI.
