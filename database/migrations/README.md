# Laravel Migration Files - MySQL to PostgreSQL

## Overview
This package contains **125 Laravel migration files** automatically generated from your MySQL database structure. These migrations are ready to use with PostgreSQL.

## What's Included
- ✅ All 125 tables from your `event` database
- ✅ Proper data type conversions (MySQL → PostgreSQL compatible)
- ✅ Nullable fields and default values
- ✅ Soft deletes (deleted_at columns)
- ✅ Timestamps (created_at, updated_at)
- ✅ Primary keys and indexes
- ✅ Foreign key relationships

## Key Data Type Conversions

| MySQL Type | Laravel/PostgreSQL Type |
|------------|------------------------|
| `BIGINT(20) UNSIGNED` | `bigInteger()` |
| `INT` | `integer()` |
| `TINYINT(1)` | `boolean()` |
| `VARCHAR(255)` | `string(255)` |
| `TEXT` | `text()` |
| `LONGTEXT` | `longText()` |
| `DECIMAL(10,2)` | `decimal(10, 2)` |
| `TIMESTAMP` | `timestamp()` |
| `DATETIME` | `dateTime()` |
| `ENUM` | `enum([values])` |
| `JSON` | `json()` |

## Installation Instructions

### Step 1: Backup Your Current Migrations
```bash
# If you have existing migrations, back them up first
mv database/migrations database/migrations_backup
```

### Step 2: Copy Migration Files
```bash
# Create migrations directory if it doesn't exist
mkdir -p database/migrations

# Copy all migration files
cp migrations/*.php database/migrations/
```

### Step 3: Configure PostgreSQL Connection
Update your `.env` file:
```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=your_database_name
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### Step 4: Create PostgreSQL Database
```bash
# Connect to PostgreSQL
psql -U postgres

# Create database
CREATE DATABASE your_database_name;

# Exit
\q
```

### Step 5: Run Migrations
```bash
# Run all migrations
php artisan migrate

# If you get errors, you can migrate one by one:
php artisan migrate --path=database/migrations/2025_11_27_081249_create_access_areas_table.php
```

## Important Tables

### Core Tables
- `users` - User authentication and profiles
- `events` - Event management
- `tickets` - Ticket types and pricing
- `bookings` - Booking records
- `venues` - Venue information
- `layouts` - Seating layouts

### Booking-Related Tables
- `master_bookings` - Master booking records
- `agents` - Agent bookings
- `pos_bookings` - Point of sale bookings
- `corporate_bookings` - Corporate bookings
- `exhibition_bookings` - Exhibition bookings

### Payment Tables
- `payment_logs` - Payment history
- `razorpays`, `paytms`, `stripes` - Payment gateway configs
- `promo_codes` - Promotional codes

### Access Control
- `roles` - User roles
- `permissions` - User permissions
- `model_has_roles` - Role assignments
- `model_has_permissions` - Permission assignments

## Common Issues & Solutions

### Issue 1: Migration Order
If you get foreign key constraint errors, you may need to adjust migration order. Tables with foreign keys should be migrated after their referenced tables.

**Solution:** Rename migration files to change execution order (adjust timestamps).

### Issue 2: Enum Values
Some enum fields might need manual adjustment based on your application logic.

**Solution:** Check enum columns in tables like `users`, `bookings`, and update values if needed.

### Issue 3: JSON Columns
MySQL `JSON` columns are converted to PostgreSQL `json` or `jsonb`.

**Solution:** If you need better performance, manually change `json()` to `jsonb()` in migrations:
```php
$table->jsonb('column_name')->nullable();
```

### Issue 4: Text Column Defaults
PostgreSQL doesn't allow default values for TEXT columns like MySQL does.

**Solution:** Already handled - default values removed from text columns in migrations.

## PostgreSQL Specific Optimizations

After running migrations, consider these optimizations:

### 1. Create Indexes for Foreign Keys
```sql
CREATE INDEX idx_bookings_user_id ON bookings(user_id);
CREATE INDEX idx_bookings_event_id ON bookings(event_id);
CREATE INDEX idx_tickets_event_id ON tickets(event_id);
```

### 2. Convert JSON to JSONB (Better Performance)
```sql
ALTER TABLE bookings ALTER COLUMN access_area TYPE jsonb USING access_area::jsonb;
```

### 3. Add GIN Indexes for JSON Searches
```sql
CREATE INDEX idx_bookings_access_area ON bookings USING GIN (access_area);
```

## Testing Migrations

### Fresh Migration
```bash
# Drop all tables and re-migrate
php artisan migrate:fresh
```

### Rollback
```bash
# Rollback last batch
php artisan migrate:rollback

# Rollback all
php artisan migrate:reset
```

### Check Status
```bash
# See migration status
php artisan migrate:status
```

## Notes

1. **No Data Migration**: These migrations only create the database structure. Data needs to be migrated separately.

2. **Auto-Increment IDs**: All `id` columns use Laravel's `id()` method which creates `BIGINT UNSIGNED AUTO_INCREMENT` in MySQL and `BIGSERIAL` in PostgreSQL.

3. **Soft Deletes**: Tables with `deleted_at` columns use `$table->softDeletes()`.

4. **Timestamps**: Tables with both `created_at` and `updated_at` use `$table->timestamps()`.

5. **Nullable Fields**: All nullable fields are marked with `->nullable()` and `->default(null)` where appropriate.

## Migration File Naming Convention

Files are named: `YYYY_MM_DD_HHMMSS_create_table_name_table.php`

This ensures proper execution order based on timestamps.

## Support

If you encounter any issues:
1. Check the error message carefully
2. Look for missing foreign key constraints
3. Verify column data types match your needs
4. Check PostgreSQL version compatibility (Laravel supports PostgreSQL 11+)

## Additional Resources

- [Laravel Migrations Documentation](https://laravel.com/docs/migrations)
- [PostgreSQL Data Types](https://www.postgresql.org/docs/current/datatype.html)
- [Laravel Schema Builder](https://laravel.com/docs/migrations#creating-tables)

---

**Generated:** November 27, 2025
**Total Tables:** 125
**Database:** event (MySQL → PostgreSQL)
