@block @block_panopto @javascript
Feature: Configure course-specific Panopto visibility synchronisation
  In order to apply appropriate Panopto access policy to an individual course
  As an administrator
  I need course settings to be governed by the site-wide override switch

  Background:
    Given the following "courses" exist:
      | fullname    | shortname |
      | Course One  | C1        |
    And I log in as "admin"

  Scenario: Course-level settings are available when site administration enables them
    Given the following config values are set as admin:
      | allow_course_visibility_overrides | 1 | block_panopto |
      | sync_hidden_courses               | 1 | block_panopto |
    And I am on "Course One" course homepage with editing mode on
    And I add the "Panopto" block
    When I configure the "Panopto" block
    Then "Course visibility synchronisation" "fieldset" should be visible
    And "Allow role provisioning while Moodle courses are hidden" "field" should be visible
    And "Synchronise all participants in hidden courses" "field" should be visible
    And "Synchronise Creators in hidden courses" "field" should be visible
    And "Synchronise Publishers in hidden courses" "field" should be visible
    And "Synchronise all participants when a course is made visible" "field" should be visible
    And "Remove access when a course is hidden again" "field" should be visible
    And the "Allow role provisioning while Moodle courses are hidden" select box should contain "Use site default (Enabled)"
    And the "Allow role provisioning while Moodle courses are hidden" select box should contain "Enabled"
    And the "Allow role provisioning while Moodle courses are hidden" select box should contain "Disabled"

  Scenario: Course-level settings are unavailable when site administration disables them
    Given the following config values are set as admin:
      | allow_course_visibility_overrides | 0 | block_panopto |
    And I am on "Course One" course homepage with editing mode on
    And I add the "Panopto" block
    When I configure the "Panopto" block
    Then "Course visibility synchronisation" "fieldset" should not be visible
    And "Remove access when a course is hidden again" "field" should not be visible
