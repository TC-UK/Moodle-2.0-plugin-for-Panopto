# Changelog

All notable changes to this project are documented here.

## 2026091700-rc4 - 2026-09-17

### Changed

- Course-level select options now use explicit translated labels: **Use site default (Enabled/Disabled)**, **Enabled**,
  and **Disabled**.
- Saving a changed effective hidden-access policy on an already-hidden course immediately queues a de-duplicated,
  keyset-paginated participant reconciliation.
- Policy comparison includes effective all-participant, Creator, Publisher, and course role-mapping changes while ignoring
  transition-only or otherwise irrelevant values.

### Fixed

- Removed unresolved `[[enabled]]` and `[[disabled]]` placeholders from Panopto block configuration.
- Enabling hidden all-participant synchronisation at course level now triggers the required sync without waiting for a
  later enrolment, role, or visibility event.

### Upgrade notes

- No database schema change is required.
- Moodle cron executes the reconciliation queued by a course block save.

## 2026091601-rc3 - 2026-09-16

### Added

- Optional site-controlled course-level configuration for all six course visibility synchronisation settings.
- Tri-state course options that inherit site defaults or explicitly enable or disable each behavior.
- PHPUnit coverage for override inheritance, suppression, preservation, restoration, and conflict handling.
- Behat coverage for site-controlled visibility of course-level configuration.

### Changed

- Stored course overrides are ignored while the site switch is disabled and restored without data loss when re-enabled.
- Removal-on-hide remains visible and configurable regardless of hidden all-participant synchronisation.
- Creator and Publisher resolution now considers course-context role assignments only; category and system roles cannot
  elevate Panopto course membership.

### Fixed

- Course-level and site-level visible-transition controls are unavailable when all hidden participants are selected.
- Contradictory removal-on-hide values cannot remove access required by effective hidden-course retention settings.

### Upgrade notes

- No database schema change is required; overrides use existing block instance configuration data.
- The course-level override site switch defaults to disabled.

## 2026091600-rc2 - 2026-09-16

### Fixed

- Creator and Publisher group membership now comes strictly from the user's effective roles mapped for that course.
- Moodle site-administrator access, unrelated system roles, and stale provisioning capabilities no longer elevate a
  course participant above Viewer unless the user also holds a mapped course role.
- Added regression coverage for ordinary students and site administrators enrolled with the Student role.
- Visible-course participant synchronisation is now unavailable and runtime-suppressed while all hidden-course
  participants are already synchronised.

## 2026091500-rc1 - 2026-09-15

### Added

- Optional hidden-course synchronisation for all active participants.
- Optional hidden-course synchronisation limited to effective Creator and/or Publisher role mappings.
- Automatic asynchronous participant synchronisation when a course is made visible.
- Optional access recalculation when a visible course is hidden again, with conflict-safe retention for hidden-course policies.
- Bounded, keyset-paginated ad hoc processing for large courses.
- PHPUnit coverage for policy, event queuing, and privacy context discovery.
- Behat coverage for dependent administration settings.
- PHPStan development configuration and a Moodle 4.5 CI gate.
- Installation, upgrade, architecture, and release-readiness documentation.

### Changed

- User group calculation now applies an explicit course visibility policy instead of relying on the user's `moodle/course:viewhiddencourses` capability.
- Course provisioning uses active enrolments and applies hidden-course policy when provisioning a hidden course.
- Privacy metadata declares the Moodle username sent to Panopto, and hidden provisioned courses are discoverable as privacy contexts.

### Fixed

- Privacy context deletion now uses the course's configured Panopto connection instead of an undefined variable.

### Upgrade notes

- No database schema change is required.
- All new settings default to disabled.
- Moodle cron is required for course visibility bulk tasks.
