@repository @repository_upload @_file_upload
Feature: Upload files
  In order to add contents
  As a user
  I need to upload files

  @javascript
  Scenario: File type validation
    Given the following "courses" exist:
      | fullname | shortname | category | idnumber |
      | Test Course | TC1 | 0 | tc1 |
    And the following "users" exist:
      | username | firstname | lastname | email |
      | student | Student | User | student@example.com |
      | teacher | Teacher | User | teacher@example.com |
    And the following "course enrolments" exist:
      | user  | course | role |
      | student | TC1 | student |
      | teacher | TC1 | editingteacher |
    And I log in as "admin"
    And I turn editing mode on
    And I am on "Test Course" course homepage
    And I add a "Assignment" to section "1" and I fill the form with:
      | name | Test |
      | assignsubmission_file_maxsizebytes | 10240 |
      | assignsubmission_file_filetypes[filetypes] | .xml |
    And I log out
    And I log in as "student"
    And I follow "Test"
    And I press "Add submission"
    When I select "lib/tests/fixtures/empty.txt" in filemanager at "File submissions"
    Then I should see "is not a valid file type"

  @javascript
  Scenario: File size validation
    Given the following "courses" exist:
      | fullname | shortname | category | idnumber |
      | Test Course | TC1 | 0 | tc1 |
    And the following "users" exist:
      | username | firstname | lastname | email |
      | student | Student | User | student@example.com |
      | teacher | Teacher | User | teacher@example.com |
    And the following "course enrolments" exist:
      | user  | course | role |
      | student | TC1 | student |
      | teacher | TC1 | editingteacher |
    And I log in as "admin"
    And I turn editing mode on
    And I am on "Test Course" course homepage
    And I add a "Assignment" to section "1" and I fill the form with:
      | name | Test |
      | assignsubmission_file_maxsizebytes | 10240 |
      | assignsubmission_file_filetypes[filetypes] | .xml |
    And I log out
    And I log in as "student"
    And I follow "Test"
    And I press "Add submission"
    When I select "lib/tests/fixtures/timezonewindows.xml" in filemanager at "File submissions"
    Then I should see "The file timezonewindows.xml is too large. The maximum size you can upload is 10240."

  @javascript
  Scenario: Upload a file in a multiple file filemanager
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1 | 0 |
    And I log in as "admin"
    And I turn editing mode on
    And I add the "Private files" block if not present
    When I follow "Manage private files..."
    And I upload "lib/tests/fixtures/empty.txt" file to "Files" filemanager
    Then I should see "1" elements in "Files" filemanager
    And I should see "empty.txt" in the "div.fp-content" "css_element"
    And I upload "lib/tests/fixtures/empty.txt" file to "Files" filemanager as:
      | Save as | empty_copy.txt |
    Then I should see "2" elements in "Files" filemanager
    And I should see "empty.txt"
    And I should see "empty_copy.txt"

  @javascript
  Scenario: Verify logs for file upload
    Given I am on the "My private files" page logged in as "admin"
    And I upload "lib/tests/fixtures/empty.txt" file to "Files" filemanager
    And I click on "Save changes" "button"
    And I am on the "System logs report" page
    And I click on "Get these logs" "button"
    Then I should see "The user with id '2' has uploaded file '/empty.txt' to the draft file area with item id" in the "File added to draft area" "table_row"
    And I should see "Size: 32 bytes. Content hash: " in the "File added to draft area" "table_row"
