# API Overlay

This directory is copied onto a **fresh Laravel 13 application** by the bootstrap scripts. It intentionally does not include Laravel framework/vendor source.

The bootstrap flow installs the Composer-compatible releases of:

- `laravel/sanctum`
- `laravel/fortify`

Then it applies this overlay and runs Composer autoload regeneration.

Do not copy this overlay onto an unrelated production Laravel application.

## Cumulative modules
The overlay contains the cumulative NurseLink application foundation from NL-001 through NL-009: identity/access, member registry, smart registration, professional portfolio, credentials/renewal, qualification evidence-readiness, communications/events, and programs/policy/advocacy.

## NL-008 overlay additions
Apply `2026_08_09_000800_create_communications_and_events_tables.php` after the NL-007 qualification migration, then run the seeders. `CommunicationTemplateSeeder` installs governed default templates used by system-triggered communication.

NL-008 adds:
- native NurseLink inbox and unread state;
- member notification preferences;
- governed message templates;
- explicit audience-filtered campaigns;
- queued campaign-recipient delivery jobs;
- per-channel delivery attempts;
- application / credential / qualification / event trigger bridge;
- published event catalog, RSVP, capacity, waitlist and attendance;
- certificate-number foundation.

### Provider truth
In-app delivery works locally. Email uses Laravel's configured mail transport. SMS, push and WhatsApp default to `unconfigured` adapters and must **not** be reported as delivered until a real provider is installed/configured.

### Queue requirement
Production should use Redis (or another durable Laravel queue backend) plus running queue workers. The `sync` driver is suitable only for development/testing and small demonstrations.


## NL-009 overlay additions
Apply `2026_08_09_000900_create_programs_policy_advocacy_tables.php` after the NL-008 communications/events migration, then rerun the role and communication-template seeders. NL-009 adds initiative, milestone, partner, beneficiary, budget, update, organizational-document, policy, stage-history and stakeholder records plus member/admin APIs and communication-trigger integration.

## NL-010 additions
Apply the `001000` migration after NL-009. New permissions: `analytics.view`, `reports.export`, `operations.view`, `privacy.manage.own`, `privacy.manage`. Run `nurselink:analytics-snapshot` and `nurselink:ops-readiness` in staging after migration.
