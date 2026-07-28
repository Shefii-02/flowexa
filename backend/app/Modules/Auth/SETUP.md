# Auth Module — Setup

## 1. Register Service Provider
Add to `bootstrap/providers.php`:
```php
App\Modules\Auth\AuthServiceProvider::class,
```

## 2. Register Middleware aliases
In `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'jwt.auth'       => \App\Modules\Auth\Http\Middleware\JwtMiddleware::class,
        'permission'     => \App\Modules\Auth\Http\Middleware\CheckPermission::class,
        'superadmin'     => \App\Modules\Auth\Http\Middleware\SuperAdminOnly::class,
        'company.active' => \App\Modules\Auth\Http\Middleware\EnsureCompanyActive::class,
    ]);
})
```

## 3. Install JWT package
```bash
composer require php-open-source-saver/jwt-auth
php artisan vendor:publish --provider="PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider"
php artisan jwt:secret
```

## 4. .env
```env
JWT_SECRET=       # auto generated
JWT_TTL=1440      # 24 hours
JWT_REFRESH_TTL=20160  # 14 days
```

## 5. Module folder structure
```
app/Modules/Auth/
├── AuthServiceProvider.php          ← registers bindings + routes
├── Routes/
│   └── api.php                      ← all auth routes
├── DTOs/
│   ├── LoginDTO.php
│   ├── RegisterDTO.php
│   └── TokenDTO.php                 ← TokenDTO, AuthResultDTO, UpdateCompanyDTO, WaCredentialsDTO
├── Repositories/
│   ├── Interfaces/
│   │   └── AuthRepositoryInterface.php
│   └── AuthRepository.php
├── Services/
│   └── AuthService.php              ← all business logic
├── Exceptions/
│   └── AuthException.php
├── Http/
│   ├── Controllers/
│   │   ├── AuthController.php       ← login, register, me, refresh, logout
│   │   └── CompanyController.php    ← show, update, wa-credentials, regenerate-token
│   ├── Requests/
│   │   ├── LoginRequest.php
│   │   ├── RegisterRequest.php      ← + UpdateCompanyRequest + WaCredentialsRequest
│   ├── Resources/
│   │   ├── UserResource.php
│   │   ├── CompanyResource.php
│   │   └── AuthResource.php         ← wraps token + user
│   └── Middleware/
│       └── JwtMiddleware.php        ← JwtMiddleware + CheckPermission + SuperAdminOnly + EnsureCompanyActive
```

## 6. API Endpoints

### Public
| Method | URL | Description |
|--------|-----|-------------|
| POST | /api/v1/auth/register | Register company + owner |
| POST | /api/v1/auth/login | Login, returns JWT |

### Protected (jwt.auth)
| Method | URL | Description |
|--------|-----|-------------|
| GET  | /api/v1/auth/me | Get current user |
| POST | /api/v1/auth/refresh | Refresh JWT token |
| POST | /api/v1/auth/logout | Invalidate token |
| GET  | /api/v1/company | Get company profile |
| PUT  | /api/v1/company | Update company profile |
| POST | /api/v1/company/wa-credentials | Set WA phone ID + token |
| POST | /api/v1/company/regenerate-token | Regenerate private API token |

## 7. Login response shape
```json
{
  "access_token": "eyJ...",
  "token_type": "bearer",
  "expires_in": 86400,
  "user": {
    "id": 1,
    "name": "Arjun Menon",
    "email": "arjun@univexa.com",
    "role": {
      "name": "owner",
      "permissions": ["contacts.view", "leads.assign", ...]
    },
    "company": {
      "id": 1,
      "name": "Univexa Technologies",
      "app_id": "WA_APP_XXXXXXXXXXXX",
      "status": "trial",
      "plan": { "name": "Trial", "messages_limit": 1000 },
      "wallet": { "balance": 1000, "is_low": false }
    }
  }
}
```

## Next Module → Staff Management
Run: tell Claude "build Staff module"
```
