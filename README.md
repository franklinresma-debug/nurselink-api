# LBS NurseLink API

Production backend API for LBS NurseLink, served under https://lbs.amsertech.com/backend.

## Stack

- PHP 8.4+
- Laravel 13
- Laravel Fortify
- Laravel Sanctum
- MySQL/MariaDB
- Database-backed sessions, cache, and queue
- Local private storage for protected documents
- Optional OCR tooling for Smart Registration document extraction

## Local Setup

The configured Windows workspace is launched with `Start-LBS-NurseLink.cmd` in
the parent folder. It includes PHP 8.4, a separate MariaDB database on port 3307,
ClamAV on loopback port 3311, and a queue worker for document extraction.
OCR models and Windows tools are in the parent's `.local` directory.

Use `Check-LBS-NurseLink.cmd` for a quick health check. Existing local credentials
are in the parent `LOCAL-ACCESS.txt`; retain the configured `.env` when syncing.
The database is local test data, not a copy of production applicant records.

For a new installation in another environment:

    composer install
    cp .env.example .env
    php artisan key:generate
    php artisan migrate
    php artisan serve

Update .env with local database credentials, local frontend URL, Sanctum domains, and mail settings.

For the configured Windows workspace, isolated regression-test configuration
is in `../.local/phpunit.xml`. It targets a separate `lbs_nurselink_checks`
database. The repository's default test configuration targets port 3306.

## Production

- Host: lbs.amsertech.com
- Server path: /var/www/lbs-nurselink-api
- Public document root: /var/www/lbs-nurselink-api/public
- Public URL prefix: https://lbs.amsertech.com/backend
- Main branch: main
- Production database: lbs_nurselink_production

## Deployment Notes

1. Back up source, storage-affecting paths, and any affected database tables before edits.
2. Apply backend changes in /var/www/lbs-nurselink-api.
3. Run targeted syntax checks or tests for changed PHP files.
4. Clear/refresh Laravel caches when configuration or routes change.
5. Verify the affected production workflow through https://lbs.amsertech.com.
6. Commit and push to GitHub.

## Smart Registration OCR Notes

Smart Registration uses backend OCR processing events/data to report deterministic page progress. Progress must be based on completed pages versus total pages and must not be confused with OCR confidence. Generated OCR artifacts, previews, uploads, and private documents belong in ignored storage paths, not in Git.

## Public Repository Safety

This repository is public. Never commit .env, private keys, database dumps, production logs, applicant files, generated OCR output, preview images, vendor dependencies, or runtime storage.
