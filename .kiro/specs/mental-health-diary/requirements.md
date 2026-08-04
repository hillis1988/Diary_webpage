# Requirements Document

## Introduction

This document defines the requirements for a personalised, web-based mental health diary application for a single primary user (Roy Hillis), created at the request of the user's doctor to support ongoing mental health care. The application allows the primary user to record daily diary entries structured around psychology best practices, receive AI-generated CBT-style feedback on each entry, track significant life milestones, review history through a calendar, and view AI-generated progress summaries over time. The application also supports read-only access for trusted third parties such as family members or clinicians.

Because the application processes mental health information, the data is treated as UK GDPR special category (health) data. The requirements therefore place strong emphasis on secure storage, access control, and privacy. The application is intended to be hosted on the IONOS hosting service under the domain royhillis.co.uk.

## Glossary

- **Diary_App**: The complete web-based mental health diary application, including its user interface, application logic, and data storage.
- **Auth_Service**: The component responsible for user registration, authentication, and session management.
- **Access_Control_Service**: The component responsible for enforcing role-based permissions across the application.
- **Diary_Service**: The component responsible for creating, storing, and retrieving diary entries.
- **AI_Feedback_Service**: The component that analyses a single diary entry and generates CBT-style recommendations.
- **AI_Summary_Service**: The component that analyses multiple diary entries over a time range and generates progress summaries relevant to CBT practice.
- **Milestone_Service**: The component responsible for creating, storing, and retrieving significant life milestones.
- **Calendar_View**: The interface component that displays diary history in a date-based layout.
- **Storage_Service**: The component responsible for persisting application data securely at rest.
- **Primary_User**: The account owner (Roy Hillis) who creates diary entries and has full access to all personal features.
- **Viewer**: A user account granted read-only access to the Primary_User's diary content.
- **Owner_Role**: The role assigned to the Primary_User granting full read and write access.
- **Viewer_Role**: The role assigned to a Viewer granting read-only access to permitted content.
- **Diary_Entry**: A single dated record containing the Primary_User's structured responses for a given day.
- **CBT_Recommendation**: AI-generated guidance based on Cognitive Behavioural Therapy principles, containing a positive focus and a suggested small change.
- **Milestone**: A user-recorded significant life event (for example, starting a new medication or making a new friend) associated with a date.
- **Session**: An authenticated period of interaction between a user and the Diary_App.
- **Special_Category_Data**: Personal data concerning health as defined by UK GDPR, requiring heightened protection.

## Requirements

### Requirement 1: User Registration

**User Story:** As the Primary_User, I want to create an account, so that I can securely access my personal mental health diary.

#### Acceptance Criteria

1. WHEN a visitor submits the registration form with a valid email address and a password meeting the password policy, THE Auth_Service SHALL create a new user account with the Owner_Role.
2. IF a visitor submits a registration request with an email address that already has an account, THEN THE Auth_Service SHALL reject the request and return a message stating that the email address is already registered.
3. IF a visitor submits a password that does not meet the password policy, THEN THE Auth_Service SHALL reject the request and return a message describing the password policy.
4. WHEN registration succeeds, THE Auth_Service SHALL store the user password using a one-way salted hashing algorithm, and THE Auth_Service SHALL store no password when registration is rejected.
5. THE Auth_Service SHALL enforce a password policy requiring a minimum of 12 characters including at least one letter and at least one digit.

### Requirement 2: User Authentication and Sessions

**User Story:** As a registered user, I want to sign in and sign out, so that only I can access diary content during my session.

#### Acceptance Criteria

1. WHEN a user submits valid credentials on the login page, THE Auth_Service SHALL establish an authenticated Session and grant access according to the user's role.
2. IF a user submits invalid credentials, THEN THE Auth_Service SHALL deny access and return a message stating that the credentials are incorrect.
3. IF a user submits invalid credentials 5 consecutive times for the same account, THEN THE Auth_Service SHALL temporarily lock the account for 15 minutes.
4. WHEN an authenticated user selects sign out, THE Auth_Service SHALL terminate the Session and require re-authentication for further access.
5. WHILE a Session is inactive for 30 minutes, THE Auth_Service SHALL terminate the Session and require re-authentication.
6. IF an unauthenticated user requests a page other than the login page or the registration page, THEN THE Access_Control_Service SHALL redirect the request to the login page.

### Requirement 3: Home Page

**User Story:** As the Primary_User, I want a home page with a personal banner, so that I have a recognisable and welcoming entry point to my diary.

#### Acceptance Criteria

1. WHEN an authenticated user opens the home page, THE Diary_App SHALL display a banner containing the text "Roy Hillis personal diary".
2. WHEN an authenticated user opens the home page, THE Diary_App SHALL display navigation controls to the diary entry page, the calendar, the summary page, and the milestones feature that the user's role permits.
3. WHERE the authenticated user holds the Viewer_Role, THE Access_Control_Service SHALL omit navigation controls for creating diary entries and milestones, and THE Access_Control_Service SHALL reject any create, modify, or delete request from that user at the system level.

### Requirement 4: Secure Storage of Sensitive Data

**User Story:** As the Primary_User, I want my mental health data stored securely, so that my sensitive information remains private and protected.

#### Acceptance Criteria

1. THE Storage_Service SHALL classify all Diary_Entry and Milestone content as Special_Category_Data.
2. THE Storage_Service SHALL encrypt all Special_Category_Data at rest using an encryption standard of AES-256 or stronger.
3. THE Diary_App SHALL transmit all data between the client and the server over a TLS 1.2 or later connection, and THE Diary_App SHALL transmit no application data outside that secure connection.
4. THE Access_Control_Service SHALL restrict access to a Primary_User's Special_Category_Data to that Primary_User and to Viewers explicitly authorised by that Primary_User.
5. WHEN a user requests deletion of their account, THE Storage_Service SHALL permanently delete that user's Diary_Entry records, Milestone records, and account data within 30 days.

### Requirement 5: Structured Diary Entry

**User Story:** As the Primary_User, I want to answer key guided questions each day, so that my diary reflects psychology best practices.

#### Acceptance Criteria

1. WHEN the Primary_User opens the diary entry page, THE Diary_Service SHALL present a set of structured questions covering mood rating, sleep quality, notable events, thoughts, and emotions.
2. THE Diary_Service SHALL require a mood rating on a defined numeric scale of 1 to 10 for each Diary_Entry.
3. WHEN the Primary_User submits a completed Diary_Entry, THE Diary_Service SHALL store the Diary_Entry associated with the submission date and the Primary_User account.
4. IF the Primary_User submits a Diary_Entry for a date that already has a Diary_Entry, THEN THE Diary_Service SHALL update the existing Diary_Entry for that date.
5. IF the Primary_User submits the diary form without providing the mood rating, THEN THE Diary_Service SHALL reject the submission, save no data from that submission, and return a message identifying the missing mood rating.
6. THE Diary_Service SHALL restrict Diary_Entry creation and modification to users holding the Owner_Role.

### Requirement 6: AI CBT-Style Feedback on Entry

**User Story:** As the Primary_User, I want AI feedback when I submit a diary entry, so that I receive CBT-style guidance for the day.

#### Acceptance Criteria

1. WHEN the Primary_User submits a Diary_Entry, THE AI_Feedback_Service SHALL generate a CBT_Recommendation based on the content of that Diary_Entry.
2. THE AI_Feedback_Service SHALL include in each CBT_Recommendation one positive focus for the Primary_User to concentrate on.
3. THE AI_Feedback_Service SHALL include in each CBT_Recommendation one small and meaningful change the Primary_User can make.
4. WHEN the AI_Feedback_Service generates a CBT_Recommendation, THE Diary_Service SHALL store the CBT_Recommendation associated with the corresponding Diary_Entry.
5. IF the AI_Feedback_Service fails to generate a CBT_Recommendation, THEN THE Diary_App SHALL store the Diary_Entry and display a message stating that feedback is temporarily unavailable.
6. WHEN the AI_Feedback_Service produces a CBT_Recommendation, THE Diary_App SHALL display a notice stating that the recommendation is automated guidance and is not a substitute for professional medical advice.

### Requirement 7: Read-Only Viewer Accounts

**User Story:** As the Primary_User, I want to grant read-only access to trusted people such as my mum or doctor, so that they can follow my progress without changing my data.

#### Acceptance Criteria

1. WHEN the Primary_User submits a request to create a Viewer account with a valid email address, THE Access_Control_Service SHALL create an account with the Viewer_Role linked to the Primary_User's data.
2. WHERE a user holds the Viewer_Role, THE Access_Control_Service SHALL grant read access to the Primary_User's Diary_Entry records, CBT_Recommendation records, Milestone records, calendar, and summary page.
3. IF a user attempts to create, modify, or delete any Diary_Entry, Milestone, or account while operating in a Viewer_Role context, THEN THE Access_Control_Service SHALL deny the action and return a message stating that the account has read-only access, including when an Owner_Role user accesses data through a viewer account context.
4. WHEN the Primary_User revokes a Viewer account, THE Access_Control_Service SHALL remove that Viewer's access to the Primary_User's data.
5. THE Access_Control_Service SHALL restrict creation and revocation of Viewer accounts to users holding the Owner_Role.

### Requirement 8: Calendar View of History

**User Story:** As a user, I want a calendar of diary history, so that I can navigate to and review entries by date.

#### Acceptance Criteria

1. WHEN a user opens the Calendar_View, THE Diary_App SHALL display a date-based layout indicating which dates have a Diary_Entry.
2. WHEN a user selects a date that has a Diary_Entry, THE Diary_Service SHALL display that Diary_Entry and its associated CBT_Recommendation.
3. WHERE a date has an associated Milestone, THE Calendar_View SHALL display an indicator for that Milestone on that date.
4. IF a user selects a date that has no Diary_Entry, THEN THE Diary_App SHALL display a message stating that no entry exists for that date.
5. WHERE the current user is authorised to view the Primary_User's data, THE Calendar_View SHALL display Diary_Entry indicators only for dates belonging to that Primary_User.

### Requirement 9: AI Progress Summary

**User Story:** As a user, I want an AI-generated summary of progress over time, so that I and my clinician can understand trends relevant to CBT practice.

#### Acceptance Criteria

1. WHEN a user opens the summary page, THE AI_Summary_Service SHALL generate a progress summary derived from Diary_Entry records within a user-selected date range.
2. THE AI_Summary_Service SHALL include in the summary trend metrics for mood rating and sleep quality across the selected date range.
3. WHERE Milestone records exist within the selected date range, THE AI_Summary_Service SHALL relate identified trends to those Milestone records.
4. IF fewer than 3 Diary_Entry records exist in the selected date range, THEN THE AI_Summary_Service SHALL display a message stating that more entries are needed to produce a reliable summary, including when summary generation also fails.
5. IF the AI_Summary_Service fails to generate a summary WHILE 3 or more Diary_Entry records exist in the selected date range, THEN THE Diary_App SHALL display a message stating that the summary is temporarily unavailable.
6. WHEN the AI_Summary_Service is invoked, THE Diary_App SHALL display a notice stating that the summary is automated analysis and is not a substitute for professional medical advice, regardless of whether a summary is produced.

### Requirement 10: Significant Milestones

**User Story:** As the Primary_User, I want to record significant life milestones, so that I can see how they affect my wellbeing over time.

#### Acceptance Criteria

1. WHEN the Primary_User submits a Milestone with a description and a date, THE Milestone_Service SHALL store the Milestone associated with that date and the Primary_User account.
2. THE Milestone_Service SHALL allow the Primary_User to categorise a Milestone by type, including at least medication, relationship, and lifestyle categories.
3. WHEN the Primary_User submits a request to modify or delete a Milestone, THE Milestone_Service SHALL apply the requested change to the stored Milestone.
4. IF the Primary_User submits a Milestone without a description or without a date, THEN THE Milestone_Service SHALL reject the submission and return a message identifying the missing field.
5. THE Milestone_Service SHALL restrict Milestone creation, modification, and deletion to users holding the Owner_Role.
