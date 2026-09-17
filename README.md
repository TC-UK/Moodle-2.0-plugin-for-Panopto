# Panopto block for Moodle

The Panopto block links Moodle courses to Panopto folders, provides Panopto single sign-on, displays recordings, and synchronises Moodle role-based access with Panopto.

This release candidate adds explicit, administrator-controlled synchronisation for hidden courses and course visibility transitions. It targets Moodle 4.5 LTS and retains the existing Panopto server requirement of version 5.4.0 or later.

## Course visibility synchronisation

The settings are under **Site administration > Plugins > Blocks > Panopto**, in the Panopto synchronisation options section. Every new setting defaults to disabled, so upgrading does not change existing behaviour until an administrator opts in.

| Setting | Behaviour |
| --- | --- |
| Allow course-level visibility synchronisation settings | Exposes inheritable overrides in each Panopto block configuration. Disabling this switch hides and ignores stored overrides without deleting them. |
| Allow role provisioning while Moodle courses are hidden | Master switch for hidden-course synchronisation. |
| Synchronise all participants in hidden courses | Includes every actively enrolled participant. This takes precedence over the role-specific options and makes show-transition synchronisation unnecessary. |
| Synchronise Creators in hidden courses | When all-participant synchronisation is off, includes users whose effective course role is in the Panopto Creator mapping. |
| Synchronise Publishers in hidden courses | When all-participant synchronisation is off, includes users whose effective course role is in the Panopto Publisher mapping. |
| Synchronise all participants when a course is made visible | Queues every active participant for Panopto synchronisation after a direct Moodle course show operation. It is unavailable while all hidden-course participants are already synchronised. |
| Remove access when a course is hidden again | Independently recalculates every active participant after a direct Moodle course hide operation. Hidden-course eligibility is applied before groups are removed. |

When course-level configuration is enabled, every course option offers **Use site default**, **Enabled**, and
**Disabled**. Inherited values follow later site-wide changes. Explicit overrides are stored in the existing Panopto
block instance configuration. If the site switch is disabled, the controls disappear and site-wide values apply; the
stored course choices are preserved and resume when the switch is enabled again.

Saving a course block after its effective hidden-access policy changes immediately queues a de-duplicated participant
reconciliation when that course is currently hidden. This includes changing between no hidden access, all participants,
or mapped Creator/Publisher roles, and changing the relevant course role mappings. The task uses the current saved policy,
so both newly required access and access that must be removed are handled. Moodle cron performs the remote Panopto calls.

Creator and Publisher selections originate from the global `block_panopto/creator_role_mapping` and `block_panopto/publisher_role_mapping` defaults. Existing per-course Panopto block mapping overrides remain authoritative for that course.

Creator and Publisher group membership is calculated only from the participant's roles assigned in that Moodle course
and mapped for that course. Moodle site-administrator access, category roles, and system roles do not grant elevated
Panopto course membership; an administrator enrolled only as a Student is synchronised as a Viewer.

When a course is hidden again, the conflict rules are:

- hidden all-participant synchronisation retains every active participant, so no removal task is queued;
- hidden all-participant synchronisation also suppresses show-transition synchronisation because access is already current;
- Creator and/or Publisher hidden synchronisation retains matching users and removes course groups from other active participants;
- with no applicable hidden synchronisation, all active participants have the hidden course groups removed.

Visibility-transition work runs as Moodle ad hoc tasks in keyset-paginated batches of 250 participants. Moodle cron must run regularly. A queued task exits safely if its setting is disabled, the course is deleted, the course visibility changes again, or the course no longer has a valid Panopto mapping.

## Requirements

- Moodle 4.5 LTS (`2024100700`) or later version supported by this plugin's CI matrix.
- A supported PHP version for the selected Moodle release.
- Panopto 5.4.0 or later.
- Moodle cron configured and running for asynchronous visibility-transition synchronisation.
- A configured Panopto server name and application key.

## Installation and upgrade

See [INSTALL.md](INSTALL.md) for installation and initial configuration. See [UPGRADE.md](UPGRADE.md) before replacing an earlier plugin version.

The installable ZIP must contain one top-level directory named `panopto`. For command-line deployment, extract that directory to `blocks/panopto` in the Moodle codebase and complete the Moodle upgrade.

## Architecture and operations

See [docs/COURSE_VISIBILITY_SYNC.md](docs/COURSE_VISIBILITY_SYNC.md) for the policy, event flow, batching model, failure handling, and extension points.

The feature does not introduce database tables or local personal-data fields. Course overrides are non-personal tri-state
values in Moodle's existing block instance configuration. Synchronisation sends the existing Moodle username, first name,
last name, email address, and calculated Panopto external group memberships to the configured Panopto service. The Moodle
Privacy API metadata and hidden-course context discovery describe that processing.

## Development and testing

GitHub Actions exercises Moodle Plugin CI against MariaDB and PostgreSQL, including Moodle 4.5. The workflow runs PHP lint, PHP Mess Detector, Moodle coding-style checks, PHPDoc validation, plugin validation, upgrade savepoint checks, targeted PHPStan analysis, PHPUnit, and Behat.

The feature-specific tests are:

- `tests/course_visibility_policy_test.php` for policy combinations and conflict resolution;
- `tests/course_visibility_config_test.php` for inheritance, site-switch suppression, preservation, restoration, and
  save-triggered reconciliation;
- `tests/rollingsync_visibility_test.php` for course visibility event queuing;
- `tests/panopto_role_mapping_test.php` for strict course-role mapping, including site administrators;
- `tests/privacy_provider_test.php` for Privacy API metadata and hidden contexts;
- `tests/behat/course_visibility_sync_settings.feature` for dependent admin controls;
- `tests/behat/course_visibility_course_overrides.feature` for course-control availability.

Static validation is not a substitute for PHPUnit, Behat, cron execution, and Panopto integration testing in a configured Moodle environment. The release status and evidence are recorded in `PRODUCTION_READINESS.md`.

## Contributing

Fork the project, create a focused branch, add tests and documentation, and submit a pull request to the [Panopto Moodle plugin repository](https://github.com/Panopto/Moodle-2.0-Plugin-for-Panopto). Do not commit credentials, generated `vendor` or `node_modules` directories, Moodle data, or local configuration.

## Copyright and licence

Copyright Panopto 2009-2026, with contributions from Spenser Jones, Hittesh Ahuja, Tim Lock, and other contributors.

This plugin is free software under the GNU General Public License version 3 or later. See [LICENSE.txt](LICENSE.txt).
