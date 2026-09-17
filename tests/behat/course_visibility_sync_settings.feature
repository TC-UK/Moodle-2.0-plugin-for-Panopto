@block @block_panopto @javascript
Feature: Configure Panopto course visibility synchronisation
  In order to control Panopto access throughout the Moodle course lifecycle
  As an administrator
  I need dependent visibility synchronisation settings

  Background:
    Given I log in as "admin"
    And I navigate to "Plugins > Blocks > Panopto" in site administration

  Scenario: Configure selective hidden-course synchronisation
    Then the following fields match these values:
      | Allow role provisioning while Moodle courses are hidden | 0 |
      | Synchronise all participants when a course is made visible | 0 |
    And "Synchronise all participants in hidden courses" "field" should not be visible
    And "Synchronise Creators in hidden courses" "field" should not be visible
    And "Synchronise Publishers in hidden courses" "field" should not be visible
    When I set the field "Allow role provisioning while Moodle courses are hidden" to "1"
    Then "Synchronise all participants in hidden courses" "field" should be visible
    And "Synchronise Creators in hidden courses" "field" should be visible
    And "Synchronise Publishers in hidden courses" "field" should be visible
    When I set the field "Synchronise all participants in hidden courses" to "1"
    Then "Synchronise Creators in hidden courses" "field" should not be visible
    And "Synchronise Publishers in hidden courses" "field" should not be visible
    And "Synchronise all participants when a course is made visible" "field" should not be visible
    And "Remove access when a course is hidden again" "field" should be visible

  Scenario: Configure access removal independently of visible-transition synchronisation
    Then "Remove access when a course is hidden again" "field" should be visible
    When I set the field "Synchronise all participants when a course is made visible" to "1"
    Then "Remove access when a course is hidden again" "field" should be visible
