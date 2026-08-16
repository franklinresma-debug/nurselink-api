NurseLink NL-011.2 - cPanel API Upload Edition
==============================================
Target folder: /home/frankresma/nurselink-api
Public domain: https://api.amsertech.com
Public document root: /home/frankresma/nurselink-api/public
Database: frankresma_nurselink
Database user: frankresma_nurselink

UPLOAD PROCEDURE
1. Open cPanel > File Manager.
2. Navigate to /home/frankresma/nurselink-api.
3. Upload NurseLink_NL-011.2_cPanel_API_Upload.zip.
4. Extract the ZIP IN THIS FOLDER. Its files are packed at ZIP root, so `artisan`, `app`, `public`, etc. should appear directly under nurselink-api.
5. Delete the ZIP from the server after successful extraction.
6. Open Manage Shell and run:
       cd /home/frankresma/nurselink-api
       bash scripts/post-upload-check.sh
7. Copy the environment template:
       cp .env.production.example .env
8. Edit `.env` in File Manager and replace ONLY secrets/placeholders, especially:
       DB_PASSWORD=...
       AUDIT_IP_HASH_KEY=...
   Do not send or screenshot these secrets.
9. Run:
       bash scripts/install-after-env.sh
10. Create the first Super Administrator when requested in the next deployment step.

IMPORTANT cPanel compatibility changes in this edition
- MySQL/MariaDB instead of PostgreSQL.
- Database sessions/cache/queue instead of Redis.
- Local private storage instead of MinIO.
- Queue processing uses short cron batches; no resident worker daemon.
- Cron schedule is 5-minute compatible with Namecheap Shared Hosting rules.
- ClamAV is disabled by default because shared cPanel does not expose a resident scanner to this app. Documents remain pending until a scanner/manual review flow is configured.

DO NOT
- Put `.env`, storage, vendor, or private documents under public_html.
- Point api.amsertech.com to the project root; it must point to nurselink-api/public.
- Install a second Laravel copy through Softaculous over this folder.
- Use the staging demo seeder on production.
