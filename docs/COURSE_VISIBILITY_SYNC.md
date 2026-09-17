# Course visibility synchronisation architecture

## Decision summary

The plugin now owns a single visibility policy rather than inheriting the caller's `moodle/course:viewhiddencourses` capability. That capability describes whether a person may view a hidden Moodle course; it is not a reliable institution-wide rule for Panopto group provisioning.

The policy is implemented by `block_panopto\local\course_visibility_policy`. Visible courses remain eligible. Hidden courses are eligible only when the master setting is enabled and either all active participants are selected or the user holds an effective course role in an enabled Creator or Publisher mapping.

Global Creator and Publisher settings initialise mappings during course provisioning. A Panopto block instance can override those mappings, so the course mapping and its matching course-context capabilities remain authoritative after provisioning.

Panopto group membership is derived directly from the user's role assignments in the course context and the course's
Creator and Publisher mapping records. Parent category and system assignments are deliberately excluded. Capability
inheritance is not used to calculate group membership. This separation prevents site-administrator access, unrelated
parent roles, or stale capability assignments from elevating a Viewer to Creator or Publisher.

## Configuration precedence

`block_panopto\local\course_visibility_config` resolves all six visibility settings. Each course value is tri-state:

1. **Use site default** reads the current plugin-wide value on every request.
2. **Enabled** overrides that value for the course.
3. **Disabled** overrides that value for the course.

Course values use Moodle's existing serialized block instance configuration rather than a new table. The site-wide
`allow_course_visibility_overrides` switch controls both display and runtime use. When it is off, the block form omits the
course controls and the resolver applies site defaults. The save path preserves existing visibility properties and rejects
forged visibility values while the switch is off. Re-enabling the switch therefore restores the previous course choices.

The block save handler compares a canonical before-and-after hidden-access signature. The signature represents no access,
all active participants, or the sorted Creator and Publisher role IDs that currently select users, together with the role
mappings that determine their final Panopto groups. If it changes while the course is hidden, one de-duplicated
`sync_course_users` task is queued immediately. The task deliberately reconciles
the current saved policy even when that policy now retains nobody, which allows it to remove obsolete Panopto groups.
Transition-only settings are excluded from this comparison.

## Event flow

### Course made visible

1. Moodle updates `course.visible` and emits `core\event\course_updated` with `other.updatedfields.visible`.
2. The observer verifies that the Panopto plugin is configured, Moodle meets the minimum version, and the visible-transition setting is enabled.
3. It queues one de-duplicated `block_panopto\task\sync_course_users` ad hoc task.
4. The task verifies that its settings and target course state are still current.
5. Active participants are read in ascending user-ID pages and synchronised with Panopto.

When all hidden-course participants are synchronised, this transition option is unavailable and any stale saved value
is ignored because the participants' access is already maintained while hidden.

### Course hidden again

The same flow runs only when removal-on-hide is enabled. The task synchronises every active participant because Panopto's external-user sync receives the complete calculated group list for that server. Users eligible under the hidden-course policy retain the relevant course groups; other participants omit those groups, causing access to be removed.

Removal-on-hide is always independently configurable and visible. If all hidden participants must retain access, the hide
transition is deliberately a no-op. This resolves contradictory saved values safely without hiding or deleting the
administrator's removal preference; if hidden-all is later disabled, that preference becomes effective again.

### Enrolment and role changes while hidden

- Active enrolment creation triggers a user sync for the hidden all-participant policy.
- Role assignment and unassignment trigger a user sync when the changed role belongs to an enabled hidden Creator or Publisher mapping.
- Existing enrolment deletion and suspension/reactivation observers continue to recalculate the user's complete Panopto group list.
- Duplicate asynchronous per-user tasks use Moodle's task uniqueness option.

### Provisioning a hidden course

Provisioning enumerates active enrolments, builds Viewer, Creator, and Publisher lists, and synchronises only users selected by the hidden-course policy. The existing `sync_after_provisioning` setting continues to control bulk user synchronisation for visible courses.

## Scalability

Visibility-transition tasks use `get_enrolled_sql()` with active enrolments and a `user.id > :afteruserid` keyset condition. Each task requests 251 records, processes at most 250, and queues the next page when the look-ahead record exists. This avoids offset scans and bounds memory and execution time for enterprise-sized courses.

Every user sync recalculates all provisioned course groups on the current Panopto server. This preserves the existing Panopto API contract and prevents one course transition from deleting valid access to another course.

## Failure and race handling

- A visibility-transition task stops if its setting is subsequently disabled. A policy-reconciliation task continues
  against the latest saved hidden policy so that disabling access can remove obsolete groups.
- A task stops if the course was deleted or its current visibility no longer matches the triggering transition.
- A task stops if the course is no longer validly provisioned to Panopto.
- Throttling wraps each Panopto user synchronisation.
- A failed ad hoc task remains visible to Moodle task administration and may be retried according to Moodle task behaviour.

These guards favour current administrator policy over stale queued work. Panopto synchronisation is idempotent because each call replaces the external group list with the current calculated list.

## Security and privacy

No new browser endpoint, capability, database table, or raw user-supplied parameter is introduced. Site administrators
control whether course overrides are exposed. Existing Moodle block configuration access controls, form validation, and
session-key protection govern authorised course-level edits. Submitted values are normalised to the three supported states.

The feature sends the existing Moodle username, first name, last name, email address, and calculated group memberships to
the already configured Panopto service. It stores boolean site configuration and non-personal tri-state course policy.
Moodle Privacy API metadata declares these user fields, and context discovery does not exclude hidden provisioned courses.

## Accessibility

The interface uses Moodle's native labelled checkboxes, selects, and `hide_if` dependency behaviour. No custom colour,
pointer-only interaction, focus management, or ARIA implementation is introduced. Behat features verify the site and
course controls' dependent visible states.

## Extension points

Future bulk triggers should queue `sync_course_users` rather than synchronously iterating a course. Changes to
site/course precedence belong in `course_visibility_config`; hidden-course eligibility belongs in
`course_visibility_policy`. Callers should not reimplement configuration precedence or role matching.
