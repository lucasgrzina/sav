# Users Specification

## Purpose

Administer platform user accounts and their profile memberships: listing with filters, creation by administrators, updates, password management, account locking and deletion. All endpoints live under `/v1/users`, require authentication, and identify users by public `guid` (never the internal id).

## Requirements

### Requirement: List Users

The system SHALL return a paginated, filterable list of users.

#### Scenario: Paginated listing with filters

- **WHEN** an authenticated user GETs `/v1/users` with optional `search`, `status`, `date_from`, `date_to` and `per_page`
- **THEN** the system returns a paginated result (`data`, `current_page`, `last_page`, `per_page`, `total`) filtered by name/email search, verification status (`verified` | `unverified` | `locked`) and creation date range

### Requirement: Create User

The system SHALL allow administrators to create a user directly, verified and ready to use, without the self-registration verification step.

#### Scenario: Admin creates a user

- **WHEN** an administrator POSTs user data to `/v1/users`
- **THEN** the system creates the user with `email_verified_at = now()`, generates a random password, and returns the created user

#### Scenario: Create a tenant owner user

- **WHEN** an administrator POSTs to `/v1/users/tenant`
- **THEN** the system creates a user intended as a tenant (vet) owner along with the required profile membership

### Requirement: Show and Update User

The system SHALL expose a single user and allow updating identity fields.

#### Scenario: Show user

- **WHEN** an authenticated user GETs `/v1/users/{guid}`
- **THEN** the system returns that user's data

#### Scenario: Update user identity

- **WHEN** an authenticated user PUTs to `/v1/users/{guid}` with `first_name`, `last_name` and/or `email`
- **THEN** the system updates only those fields; passwords are changed through dedicated endpoints

### Requirement: User Profile Memberships

The system SHALL manage a user's profile memberships (associations to a tenant/entity with a role).

#### Scenario: Add a profile to a user

- **WHEN** an authenticated user POSTs to `/v1/users/{guid}/profiles`
- **THEN** the system creates a new profile membership for that user

#### Scenario: Change a profile's role

- **WHEN** an authenticated user PATCHes `/v1/users/{guid}/profiles/{profileGuid}/role`
- **THEN** the system updates the role assigned to that profile membership

### Requirement: Password Management

The system SHALL let administrators change or reset a user's password without knowing the current one.

#### Scenario: Change password

- **WHEN** an administrator PATCHes `/v1/users/{guid}/change-password` with `password` and `password_confirmation`
- **THEN** the system updates the password without requiring the current password

#### Scenario: Reset password

- **WHEN** an administrator POSTs `/v1/users/{guid}/reset-password`
- **THEN** the system generates a new random password and returns it so the administrator can relay it to the user

### Requirement: Account Locking

The system SHALL allow toggling a user's locked state.

#### Scenario: Lock and unlock

- **WHEN** an administrator PATCHes `/v1/users/{guid}/toggle-lock`
- **THEN** the system sets `locked_at = now()` if unlocked, or clears `locked_at` and resets `failed_login_attempts` if locked

#### Scenario: Cannot lock self

- **WHEN** a user attempts to toggle the lock on their own account
- **THEN** the system rejects the request

### Requirement: Delete User

The system SHALL allow deleting a user, except the authenticated user themselves.

#### Scenario: Delete another user

- **WHEN** an administrator DELETEs `/v1/users/{guid}` of another user
- **THEN** the system deletes the user

#### Scenario: Cannot delete self

- **WHEN** a user attempts to delete their own account
- **THEN** the system rejects the request
