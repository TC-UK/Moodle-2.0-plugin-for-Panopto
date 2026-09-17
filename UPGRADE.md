# Upgrade guide

## Upgrade to 2026091700-rc4

This release changes the plugin version from `2026091601` to `2026091700`. It replaces unresolved course-option labels
with explicit translated labels and queues a participant reconciliation when saving a changed hidden-access policy for
an already-hidden course. Sites upgrading directly from upstream version `2026073000` also receive the earlier visibility
settings, course overrides, strict course-role mapping, and observers. No XMLDB schema change is required.

### Before upgrading

1. Back up the Moodle database and the existing `blocks/panopto` directory.
2. Record the installed plugin version and current Panopto synchronisation settings.
3. Confirm cron health and resolve failed Panopto ad hoc tasks.
4. Test the upgrade with a copy of the site and a non-production Panopto tenant.

### Upgrade procedure

1. Replace the complete `blocks/panopto` directory with the new `panopto` package directory. Do not merge directories and do not retain deleted files.
2. Run the Moodle upgrade through the administration UI or `php admin/cli/upgrade.php --non-interactive`.
3. Confirm the installed version is `2026091700`.
4. Purge caches.
5. Review the new settings under **Site administration > Plugins > Blocks > Panopto**.

The new site switch for course-level overrides defaults to disabled. Existing site-wide visibility settings retain their
values. Course overrides use the existing block instance configuration and require no data migration.

### Validation after upgrading

- Confirm normal block rendering and single sign-on in an existing visible provisioned course.
- Confirm an enrolment or role change still produces the expected Panopto group membership.
- Confirm a site administrator enrolled as a Student receives Viewer access only, unless that user also has a mapped
  Creator or Publisher course role.
- Confirm a mapped Creator or Publisher role assigned at system or category context does not elevate a course Student.
- Enable course overrides, save a representative mix of inherited and explicit values, disable the site switch, and
  confirm site defaults apply. Re-enable the switch and confirm the stored course values return.
- Confirm course selects show **Use site default (Enabled)** or **Use site default (Disabled)**, **Enabled**, and
  **Disabled**, without unresolved double-square-bracket language placeholders.
- In a hidden test course, change the effective policy from no hidden access to all participants, save the block, run cron,
  and confirm each active participant receives the expected Viewer, Creator, or Publisher membership from course roles.
- Exercise direct course show and hide transitions in a test course.
- Run cron until no `block_panopto\task\sync_course_users` tasks remain.
- Review failed task logs before enabling the feature for large course populations.

### Rollback

First disable all six visibility synchronisation settings to stop new work. Allow or remove queued ad hoc tasks only through approved Moodle operational procedures. A code rollback from version `2026091700` to older source is a downgrade and is not supported by Moodle; restore the pre-upgrade code and database backups together if a full rollback is required.
