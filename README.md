# FASHION HUB (PHP + MySQL)

Production-ready setup for a static frontend with a PHP JSON API backend.

## 1. Requirements
- PHP 8.0+ (7.4 may work, 8.x recommended)
- MySQL 5.7+ or 8+
- Apache or Nginx with PHP enabled

## 2. Database Setup
Run the schema once:

```bash
mysql -u <user> -p < database/schema.sql
```

## 3. Environment Configuration
Set these environment variables on your server:

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `CORS_ALLOWED_ORIGINS` (comma-separated, required if frontend/API origins differ)
- `ALLOW_DEMO_VERIFICATION` (`0` in production, `1` for local demo OTP/token flow)
- `ADMIN_REVIEW_KEY` (required for admin legitimacy review dashboard)
- `SMS_PROVIDER`, `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER`
- `APP_NAME`, `APP_BASE_URL`, `MAIL_FROM`

Use `.env.example` as the reference values.

## 4. File Permissions
Ensure the upload directory is writable by the web server user:

- `backend/uploads/products/`

Example (Linux):

```bash
chown -R www-data:www-data backend/uploads
chmod -R 775 backend/uploads
```

## 5. Local Run (Apache/PHP built-in)
From project root:

```bash
php -S 127.0.0.1:8000
```

Open:

- `http://127.0.0.1:8000/index.html`

## 6. Production Notes
- Keep `DB_PASS` out of source control.
- Restrict `CORS_ALLOWED_ORIGINS` to trusted domains only.
- Set `ALLOW_DEMO_VERIFICATION=0` in production.
- Set a strong `ADMIN_REVIEW_KEY` and do not expose it in client code.
- Configure SMS and email provider variables before disabling demo verification.
- Upload directory is protected by `backend/uploads/products/.htaccess`.
- For HTTPS deployment, session cookies automatically use `Secure` when `HTTPS` is on.
- Product listing uses paginated API responses (default 12 per page).

### OTP Delivery Notes
- Phone OTP delivery uses Twilio in `backend/api/auth/send_otp.php`.
- Email verification token delivery uses PHP `mail()` in `backend/api/auth/send_email.php`.
- If delivery fails and `ALLOW_DEMO_VERIFICATION=1`, token/OTP is returned in API response for testing.
- If delivery fails and `ALLOW_DEMO_VERIFICATION=0`, API returns an error.

## 7. Admin Review Workflow
- Open `admin.html` in the browser.
- Enter the `ADMIN_REVIEW_KEY` when prompted.
- Review uploaded legitimacy documents.
- Approve or reject retailer applications with optional notes.

## 8. Smoke Test Checklist
1. Register/verify a retailer.
2. Add a product with an image file.
3. Confirm image saved under `backend/uploads/products/`.
4. Approve the retailer in `admin.html`.
5. Verify product posting is disabled before approval and enabled after approval.
6. Verify product appears on home and product detail pages.
7. Submit contact form and confirm row in `contact_messages`.
