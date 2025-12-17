# Learnrail API

**Pure PHP REST API for the Learnrail E-Learning Platform**

This is the backend API for Learnrail, providing all the endpoints needed for the mobile app and admin panel.

---

## Features

- JWT Authentication (register, login, refresh tokens)
- User management & profiles
- Course catalog with modules & lessons
- Video lesson progress tracking
- Quiz system with attempts
- Goal tracking with milestones
- Accountability partner messaging
- Subscription & payment management
- Gamification (points, badges, achievements)
- Admin API for content management

---

## Requirements

- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+
- Apache with mod_rewrite or Nginx

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/BluLTD/learnrail-api.git
cd learnrail-api
```

### 2. Create database

```bash
mysql -u root -p
CREATE DATABASE learnrail;
exit;

mysql -u root -p learnrail < database/schema.sql
```

### 3. Configure environment

Copy and edit the environment variables in your server config or create a `.env` loader:

```
DB_HOST=localhost
DB_NAME=learnrail
DB_USER=root
DB_PASS=your_password

JWT_SECRET=your-super-secret-jwt-key-change-in-production
APP_ENV=development
API_BASE_URL=http://localhost/learnrail-api

PAYSTACK_SECRET_KEY=sk_test_xxx
PAYSTACK_PUBLIC_KEY=pk_test_xxx

BUNNY_CDN_URL=https://your-zone.b-cdn.net
BUNNY_API_KEY=your-bunny-api-key
```

### 4. Configure Apache virtual host

```apache
<VirtualHost *:80>
    ServerName api.learnrail.local
    DocumentRoot /path/to/learnrail-api/public

    <Directory /path/to/learnrail-api/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 5. Set permissions

```bash
chmod -R 755 uploads/
chmod -R 755 logs/
```

---

## Project Structure

```
learnrail-api/
├── config/
│   ├── config.php          # App configuration
│   └── database.php        # Database connection
│
├── controllers/
│   ├── AuthController.php
│   ├── UserController.php
│   ├── CourseController.php
│   ├── GoalController.php
│   └── ...
│
├── core/
│   ├── Controller.php      # Base controller
│   ├── JWT.php             # JWT handling
│   ├── Request.php         # Request helper
│   ├── Response.php        # Response helper
│   └── Router.php          # Simple router
│
├── database/
│   └── schema.sql          # Database schema
│
├── logs/                   # Error logs
│
├── public/
│   ├── index.php           # Entry point
│   └── .htaccess           # URL rewriting
│
├── routes/
│   └── api.php             # Route definitions
│
├── uploads/                # Uploaded files
│
└── README.md
```

---

## API Endpoints

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/auth/register` | Register new user |
| POST | `/api/auth/login` | Login user |
| POST | `/api/auth/refresh` | Refresh access token |
| POST | `/api/auth/forgot-password` | Request password reset |
| POST | `/api/auth/reset-password` | Reset password |
| GET | `/api/auth/me` | Get current user |
| POST | `/api/auth/logout` | Logout |

### Courses

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/courses` | List courses |
| GET | `/api/courses/{slug}` | Get course details |
| POST | `/api/courses/{id}/enroll` | Enroll in course |
| GET | `/api/lessons/{id}` | Get lesson |
| POST | `/api/lessons/{id}/complete` | Mark lesson complete |

### Goals (Subscription required)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/goals` | List user's goals |
| POST | `/api/goals` | Create goal |
| GET | `/api/goals/{id}` | Get goal |
| PUT | `/api/goals/{id}` | Update goal |
| DELETE | `/api/goals/{id}` | Delete goal |
| POST | `/api/goals/{id}/checkin` | Check in on goal |

### Subscriptions

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/subscription-plans` | List available plans |
| GET | `/api/subscriptions` | User's subscriptions |
| POST | `/api/subscriptions` | Create subscription |

### Gamification

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/leaderboard` | Get leaderboard |
| GET | `/api/badges` | User's badges |
| GET | `/api/achievements` | User's achievements |

---

## Authentication

All protected endpoints require a Bearer token in the Authorization header:

```
Authorization: Bearer <your_jwt_token>
```

---

## Response Format

### Success Response

```json
{
    "success": true,
    "message": "Success message",
    "data": { ... }
}
```

### Error Response

```json
{
    "success": false,
    "message": "Error message",
    "errors": { ... }
}
```

### Paginated Response

```json
{
    "success": true,
    "data": [ ... ],
    "meta": {
        "current_page": 1,
        "per_page": 20,
        "total": 100,
        "last_page": 5,
        "has_more": true
    }
}
```

---

## Default Admin Login

After running the schema.sql, a default admin user is created:

- **Email:** admin@learnrail.org
- **Password:** admin123

**Change this immediately in production!**

---

## Development

### Running locally

Use PHP's built-in server for development:

```bash
cd public
php -S localhost:8000
```

API will be available at `http://localhost:8000/api`

---

## Developed By

**Gsoft Interactive Systems Ltd**

---

## License

Proprietary software developed for Blue Horizon Ltd. All rights reserved.
