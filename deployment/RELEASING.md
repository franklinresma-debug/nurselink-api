# NurseLink production releases

The API repository coordinates a single release containing an exact API commit
and an exact frontend commit. Frontend CI remains in `lbs-nurselink-web`.
`backend-ci.yml` runs on pull requests and main pushes and is reused by production
releases. Application deployment is never triggered merely by pushing or merging.

## Release process

1. Finish changes locally, open pull requests, and merge reviewed changes into
   each repository's `main` after its checks pass.
2. Review any differences still present on the live server. The release gateway
   rejects modified or untracked application source; it never resets live edits.
   Reconcile those changes through GitHub before the first automated deployment.
3. In the API repository, open Actions > Production release > Run workflow.
   Select **main** and supply both full, 40-character commit IDs. Both IDs must
   belong to their repository's main history. Use an API commit that contains
   this release tooling. Leave migrations unchecked unless you have reviewed
   the pending migrations as additive and compatible with the previous release.
4. Backend checks and the frontend production build run against those exact IDs.
   The workflow produces the backend production dependencies and frontend build.
5. Review the checks, exact IDs, affected workflows and migration plan. The
   `production` environment then requires explicit approval from the repository
   owner before the job can read the deployment key or connect to the server.
6. After approval, the gateway checks live source drift and migration policy,
   prepares separate release directories, enters maintenance mode, stops the
   queue worker and scheduler, backs up the live database, activates the release,
   runs allowed migrations, rebuilds caches and reloads PHP. It checks the home
   page, administrator page, backend health, branding settings and logo image
   before restarting background services and recording success.
7. Verify applicant/admin login, changed menus, registration and a synthetic
   document upload/OCR workflow in the browser. External integrations require
   their own functional checks. The HTTP checks are not a complete user test.

Deployment has a brief maintenance period. Only one production workflow and one
server gateway operation can run at a time. An in-progress release is not canceled
when another release is requested.

## Approval configuration

Configure the API repository's **production** environment with:

- Required reviewer: `franklinresma-debug`.
- Administrator bypass disabled.
- Selected deployment branches: exactly the `main` branch, no tags.
- Self-review allowed so the sole repository owner can dispatch and personally
  approve a release. Automation must never submit that approval on their behalf.

The validation job checks the actual environment rules through GitHub's API. A
missing or weakened gate fails the workflow before production credentials are
requested. Adding `environment: production` to YAML alone does not create approval
protection. See [GitHub environment protection](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments).

Environment secrets:

| Name | Purpose |
| --- | --- |
| `LBS_DEPLOY_KEY` | Dedicated SSH key for the forced-command `lbs-release` account |
| `LBS_KNOWN_HOSTS` | Server host key verified using the existing trusted connection |

Environment variables: `LBS_DEPLOY_HOST` and `LBS_DEPLOY_PORT`.
The SSH identity cannot open an interactive shell, forward ports, or run arbitrary
commands. The root-owned gateway accepts only validated `plan`/`deploy` requests.
The existing administrator SSH key is not shared with GitHub.

## Server installation and storage

The reviewed `release-gateway.py` is installed as `/usr/local/sbin/lbs-release`,
owned by root and executable only through the restricted service configuration.
Updating the application does not update this installed copy: gateway changes
require a separate reviewed administrator installation and rerun of its tests.

The application locations remain `/var/www/lbs-nurselink-api` and
`/var/www/lbs-nurselink-web`. The first successful deployment retains the original
directories under `/var/www/lbs-nurselink-releases/legacy-*` and replaces the public
paths with links to the prepared release. Subsequent releases switch those links.
The previous production `.env` and storage stay shared, preserving branding,
uploads, private documents and credentials. Local databases, `.local`, Windows
tools and local credentials are not transferred. Never delete a legacy directory
while any release still uses its environment or storage.

Each release directory retains a private manifest, deployment log and database
backup. Retain enough disk space and review retention manually; this workflow
does not delete prior releases or backups automatically.

## Failure and recovery

- Before activation, validation or backup failure leaves application source intact.
- If activation or health checks fail and no database migration started, the gateway
  restores both previous application paths and resumes the previous services.
- Once a migration starts, a failure leaves maintenance mode enabled and background
  services stopped. MySQL schema changes may already have committed. Inspect the
  private log and migration state before deciding whether to fix forward or restore
  application paths. Never automatically import an older database over newer records.

For recovery, the server administrator reads the failed release's `manifest.json`
for `previous_paths`, `previous_commits`, `pending_migrations` and
`database_changed`. After confirming schema compatibility, restore the two links
to those exact previous paths, refresh Laravel caches, reload `php8.4-fpm`, exit
maintenance mode and restart the services that were active. Verify health and
the affected workflows. The backup is available for a separately reviewed data
recovery, not an automatic migration rollback.

Run the independent gateway tests with:

```sh
python3 -m unittest discover -s deployment/tests -v
```

The tests simulate activation, health failure, migration failure, live source
drift, corrupt packages and missing approval rules without using production data.
Laravel cache/service guidance: [Deployment](https://laravel.com/framework/docs/13.x/deployment).
