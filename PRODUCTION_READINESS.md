# Production readiness review

## 1. Executive Summary

Version `2026091700-rc4` implements administrator-controlled Panopto role synchronisation for hidden Moodle courses and direct course visibility transitions. It adds site-controlled, inheritable course overrides, friendly translated course-option labels, and save-triggered reconciliation when the effective hidden-access policy changes. Panopto Creator and Publisher membership is restricted to direct course role assignments, so site administration, system roles, and category roles do not elevate course membership. The implementation is packaged as a release candidate for isolated Moodle 4.5 testing.

The source passed the available syntax, coding-style, mess-detection, static-analysis, manifest, workflow, XML, XMLDB, and package-structure checks. A configured Moodle database, browser-test service, and Panopto test tenant were not available in the build environment. PHPUnit, Behat, upgrade execution, cron execution, and live Panopto integration therefore remain unverified.

**Overall classification: NOT READY FOR PRODUCTION.** The package is suitable for installation in an isolated test environment only. Production promotion requires the runtime evidence listed in sections 9, 11, and 13.

## 2. Moodle Compliance Review

| Requirement | Status | Evidence |
| --- | --- | --- |
| Moodle 4.5 compatibility | PASS (static) | `version.php` requires `2024100700`; analysis used an official `MOODLE_405_STABLE` source tree. |
| Plugin structure and frankenstyle | PASS | Component is `block_panopto`; the package validator requires the single top-level directory `panopto/`. |
| PHP syntax | PASS | PHP 8.3.33 lint passed for all 353 plugin PHP files, excluding generated `vendor/`. |
| Moodle coding style | PASS | Moodle PHPCS passed every changed or added PHP file with warnings disabled. |
| PHP Mess Detector | PASS | Moodle Plugin CI PHPMD rules passed the new policy, task, and PHPUnit files. |
| Static type/API analysis | PASS | PHPStan with `phpstan-moodle`, against the staged Moodle 4.5 plugin, reported no errors. |
| Event observer refresh | PASS (static) | Version and latest upgrade savepoint are both `2026091700`; event callbacks exist and are syntactically valid. |
| Full plugin validation | NOT RUN | Moodle Plugin CI validation requires an installed Moodle database and generated configuration. |

No deprecated API was intentionally introduced. The feature uses Moodle event, enrolment, context, administration-setting, task, configuration, and Privacy APIs.

## 3. Security Audit

The feature adds no browser endpoint, raw request parameter, or file operation. Site-wide values use Moodle's administration settings framework. Course values use the existing block configuration form. Moodle core supplies authentication, context-aware block editing authorisation, validated form submission, and session-key protection.

Course settings are normalised to inherit, disabled, or enabled. When the site switch is off, the fields are omitted, submitted visibility values are ignored, and existing values remain stored. Panopto group membership is resolved from direct course-context role IDs and course mapping records. Moodle's site-administrator override, system and category roles, and unrelated inherited capabilities are deliberately excluded from this access calculation.

The bulk task accepts only stored integer and boolean custom data, rechecks current configuration and course visibility, confirms a valid Panopto mapping, and obtains participants through Moodle's enrolment SQL. Its only additional SQL condition uses a named parameter. No user-controlled SQL, HTML, URL, filesystem path, or shell content is constructed.

The observers and block save handler avoid performing bulk remote calls in the request transaction. They queue de-duplicated ad hoc work. A canonical policy signature ignores transition-only and inactive subordinate values, preventing unrelated saves from causing bulk work.

Static review found no new SQL injection, XSS, CSRF, path traversal, secret disclosure, authentication bypass, or privilege-escalation surface. Live penetration and integration testing remain outside the available evidence.

## 4. GDPR Review

The integration processes personal data in Panopto: Moodle username, first name, last name, email address, and calculated external group memberships. The release corrects the external-location metadata to include the username and ensures hidden provisioned courses are discoverable through Privacy API context lookup.

No new local personal-data table or field is introduced. The feature adds boolean site configuration and non-personal tri-state policy values in existing block instance configuration. Existing export and deletion paths remain in the provider, and the undefined course connection variable in course-context deletion has been corrected.

The lawful basis, controller/processor arrangement, Panopto retention, data residency, and data-processing agreement remain institutional responsibilities. Privacy PHPUnit coverage is authored, but a Moodle test database run is pending.

## 5. Accessibility Review

The user interface consists solely of Moodle-native labelled administration checkboxes, block-form selects, static help text, and `hideIf` dependencies. It introduces no custom colour, non-semantic control, pointer-only operation, custom focus management, or ARIA behavior. The controls inherit Moodle's keyboard and screen-reader implementation.

Behat scenarios cover the visibility of dependent site settings, site-controlled availability of course settings, and the exact translated select labels. Browser execution and assistive-technology verification have not run in this environment, so accessibility runtime validation remains pending.

## 6. Performance Review

Course visibility and effective hidden-policy changes queue background work rather than calling Panopto during the request. Each ad hoc task requests at most 251 active users, processes 250, and advances with the indexed `user.id > :afteruserid` keyset. This bounds memory and avoids offset scans for large courses.

Each user call is wrapped by the plugin's existing throttling layer and recalculates the full set of valid groups on the target Panopto server. Identical initial and continuation tasks use Moodle's uniqueness option. Stale tasks exit if the setting or target visibility changes.

Performance risk remains because one Panopto call is required per selected participant and each call recalculates that user's provisioned courses. Load, rate-limit, retry, and recovery testing with enterprise course sizes is required before production enablement.

## 7. Database Review

No schema change is required. Course overrides use Moodle's existing `block_instances.configdata`; role-mapping and folder-mapping tables are read through Moodle's database API. `db/install.xml` parsed successfully and validated against Moodle 4.5's XMLDB XSD. The upgrade step is a no-schema savepoint.

The bulk participant query is derived from `get_enrolled_sql()` for active enrolments, joins by user ID, excludes deleted users, orders by user ID, and uses keyset pagination. A real upgrade from `2026073000` and execution against both MariaDB and PostgreSQL remain CI requirements.

## 8. Code Quality Review

Site/course precedence is centralised in `block_panopto\local\course_visibility_config`; visibility decisions and canonical hidden-access comparison are centralised in `block_panopto\local\course_visibility_policy`. Event observers and block saves decide when to queue work, while `block_panopto\task\sync_course_users` owns bounded processing. Existing `panopto_data` remains responsible for server-specific group calculation and remote synchronisation.

The course-override switch defaults to disabled, each course option defaults to inheriting the site value, and all settings have English labels and descriptions. Stored overrides are preserved but ignored while disabled. Architecture, installation, upgrade, operational behavior, and changes are documented. Composer development dependencies are locked and the manifest validates strictly.

## 9. Testing Review

Automated sources include:

- PHPUnit policy tests for disabled, all-participant, Creator, Publisher, visible-course, role-change, and conflict combinations;
- PHPUnit configuration tests for inheritance, site-switch suppression, save-path preservation, restoration, contradictory settings, and save-triggered hidden-course reconciliation;
- PHPUnit observer tests for show, selective hide, conflicting hide, hidden enrolment, and hidden mapped-role assignment;
- PHPUnit role-mapping tests for Viewer, Creator, Publisher, combined roles, stale capabilities, site administrators, and mapped system roles;
- PHPUnit Privacy API tests for metadata and hidden provisioned course contexts;
- Behat scenarios for dependent administration controls, course-level control availability, and friendly select labels;
- GitHub Actions coverage for Moodle 4.5 on MariaDB and PostgreSQL, plus the upstream later-version matrix.

Executed successfully in the build environment:

- PHP 8.3.33 syntax checks for 353 PHP files;
- Moodle PHPCS for all changed and added PHP;
- Moodle Plugin CI PHPMD rules for new PHP;
- PHPStan with Moodle 4.5 stubs and bootstrap;
- strict Composer validation;
- GitHub Actions YAML parsing;
- parsing of all plugin XML files;
- Moodle XMLDB XSD validation;
- Git whitespace-error checking.

Not executed because the environment lacks a configured Moodle test database, web driver, and Panopto test tenant:

- PHPUnit;
- Behat;
- full Moodle Plugin CI validation and PHPDoc checks;
- real database upgrade and savepoint execution;
- cron/ad hoc task execution;
- MariaDB/PostgreSQL comparison;
- Panopto SOAP/API integration and rate-limit testing.

## 10. Documentation Review

`README.md`, `INSTALL.md`, `UPGRADE.md`, `CHANGELOG.md`, this review, and `docs/COURSE_VISIBILITY_SYNC.md` describe the implementation and current limitations. They cover installation, configuration, policy precedence, direct course show/hide behavior, cron, batching, privacy, rollback, testing, and contribution workflow.

Documentation intentionally does not claim category-driven visibility transitions. The implemented trigger is a direct Moodle course `visible` field update.

## 11. Deployment Readiness Review

The release package is built with one top-level `panopto/` directory and excludes repository metadata, generated dependency directories, local tooling, caches, and temporary files. Its contents are re-read after creation, forbidden paths are checked, and the packaged version is verified.

Installation readiness describes archive layout, not successful runtime installation. Deploy only to an isolated Moodle 4.5 test site with a non-production Panopto tenant. Take code and database backups, complete the Moodle upgrade, purge caches, run cron, and execute the smoke tests in `INSTALL.md`.

Production deployment is blocked until the pending tests in section 9 pass and operational owners approve performance and privacy behavior.

## 12. Risk Register

| Risk | Likelihood | Impact | Mitigation / required evidence |
| --- | --- | --- | --- |
| Runtime defect not exposed by static analysis | Medium | High | Run PHPUnit, Behat, upgrade, cron, and observer tests in Moodle 4.5. |
| Panopto API behavior or tenant policy differs | Medium | High | Use a non-production Panopto tenant and verify group additions, retention, and removals. |
| Large visibility transition produces remote-call backlog | Medium | Medium | Load-test representative courses, monitor ad hoc task latency, and tune existing throttling. |
| Category hide/show does not trigger this direct-course observer | Medium | Medium | Documented scope; test direct course visibility operations or design a separate category transition workflow. |
| Stale access for participants affected by an external failure | Low to medium | High | Monitor failed tasks, verify retry behavior, and reconcile against Panopto audit data. |
| Institutional privacy obligations are incomplete | Low to medium | High | Review DPA, lawful basis, retention, residency, and privacy-request behavior before enablement. |
| Preserved course overrides surprise administrators after re-enablement | Low | Medium | The site description, course help text, upgrade guide, and operational test explicitly document restoration behavior. |
| Frequent policy edits create participant reconciliation load | Low | Medium | Only effective hidden-access signature changes on currently hidden courses queue work; identical tasks are de-duplicated and processed in bounded pages. |

## 13. Final PASS/FAIL Checklist

| Gate | Result | Evidence / reason |
| --- | --- | --- |
| Critical security vulnerability absent in changed surface | PASS (static review) | No new endpoint or unvalidated request surface; parameterised Moodle APIs only. |
| Capability enforcement for new administrative actions | PASS | Moodle core administration settings and existing context-aware block editing control site and course changes. |
| SQL injection protection | PASS | Core enrolment SQL plus named parameters; no concatenated user input. |
| XSS protection | PASS | No new rendered user content or custom output. |
| CSRF protection | PASS | No new action endpoint; Moodle settings form supplies session protection. |
| Privilege escalation protection | PASS (static review) | Synchronisation uses direct course role assignments; site administration and parent-context roles are excluded. |
| Privacy API compliance | PASS (implementation), FAIL (runtime evidence) | Metadata and context behavior implemented; PHPUnit not executed. |
| Data-loss risk controlled | PASS (design), FAIL (integration evidence) | Recalculation retains policy-selected groups, but live removal behavior is unverified. |
| Upgrade path | PASS (static), FAIL (runtime evidence) | Savepoint and version align; a real Moodle upgrade was not executed. |
| Sensitive-action validation | PASS | Task state, course, visibility, mapping, and active enrolment are revalidated. |
| Automated Moodle test execution | FAIL | PHPUnit and Behat require unavailable services. |
| Install-ready archive structure | PASS | Archive verification enforces `panopto/` root and forbidden-path exclusions. |
| Production release | **FAIL** | Mandatory runtime evidence is incomplete. |

## 14. Final Release Recommendation

Release `2026091700-rc4` as an **isolated test release only**. Do not deploy it to production or describe it as production-ready. Promote it only after the GitHub Actions matrix passes, a Moodle 4.5 upgrade succeeds, cron drains save-triggered and visibility tasks, live Panopto tests demonstrate the site/course precedence matrix, and institutional security/privacy owners accept the results.
