# Installation

## Prerequisites

Before installation, confirm that:

- the target site is Moodle 4.5 LTS or a later release covered by the plugin CI matrix;
- a supported PHP version and required Moodle PHP extensions are installed;
- the Panopto service is version 5.4.0 or later;
- a site backup and a database backup are available;
- Moodle cron runs frequently;
- Panopto server names and application keys are available through the institution's approved secret-management process.

Never place application keys in source control, documentation, tickets, or test fixtures used outside an isolated test environment.

## Install through Moodle administration

1. Sign in as a site administrator.
2. Open **Site administration > Plugins > Install plugins**.
3. Upload the install-ready ZIP. Its single top-level directory must be `panopto`.
4. Confirm the detected component is `block_panopto`.
5. Continue through the Moodle database upgrade page.
6. Review all displayed upgrade messages.
7. Open **Site administration > Plugins > Blocks > Panopto** and configure the Panopto server settings.
8. Run cron and review the scheduled-task log for errors.

## Install by filesystem deployment

1. Put the site into maintenance mode according to local change procedures.
2. Extract the package so the plugin entry point is `blocks/panopto/version.php`.
3. Ensure the web service account can read the files and no local web-server write permissions were introduced unnecessarily.
4. Run `php admin/cli/upgrade.php --non-interactive` from the Moodle root, or complete the browser-based upgrade.
5. Purge caches with Moodle's administration UI or `php admin/cli/purge_caches.php`.
6. Leave maintenance mode after validation.

## Initial visibility configuration

All visibility settings default to off. Enable only the required policy:

1. Enable **Allow role provisioning while Moodle courses are hidden**.
2. Choose either all active participants or one or both mapped-role options. Selecting all participants hides the role-specific controls because it supersedes them.
3. Enable **Synchronise all participants when a course is made visible** if a direct show operation should trigger a bulk sync. This option is unavailable while all hidden-course participants are synchronised.
4. Independently enable **Remove access when a course is hidden again** if a direct hide operation should recalculate access. The option remains available with every hidden-course policy; access that policy requires is retained.
5. Enable **Allow course-level visibility synchronisation settings** if authorised course editors may override these defaults.
6. Save changes and confirm Moodle cron is processing ad hoc tasks.

When course configuration is allowed, open the Panopto block configuration in a course. Each visibility option can use
the current site default, or be explicitly enabled or disabled for that course. Turning the site switch off hides and
ignores all course overrides but preserves them in block configuration; turning it back on restores their effect.

When a save changes the effective hidden-access policy of a course that is already hidden, Moodle immediately queues a
bounded reconciliation of that course's active participants. Run cron to perform the Panopto calls. Transition-only
settings do not trigger an unrelated backfill, and no save starts an unbounded site-wide operation.

## Post-install smoke test

Use a non-production Panopto tenant and a test course:

1. Provision a hidden course containing test Viewer, Creator, and Publisher users.
2. Verify each selected hidden-course policy combination against the expected Panopto groups.
3. Show the course and verify all active participants are synchronised when enabled.
4. Hide the course and verify Viewer access is removed while configured Creator and Publisher access is retained.
5. Confirm suspended enrolments do not receive new access.
6. Review Moodle task logs and Panopto audit information for unexpected errors.

Do not promote the release candidate to production until the automated and integration checks listed in `PRODUCTION_READINESS.md` pass in the target environment.
