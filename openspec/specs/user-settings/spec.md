# User Settings Specification

## Purpose

Store per-user preferences as typed key/value entries (string, integer, boolean or json) parsed on read. Each user reads and updates only their own settings. Endpoints live under `/v1/user/settings` and require authentication.

## Requirements

### Requirement: Read User Settings

The system SHALL return the authenticated user's settings with values parsed to their declared type.

#### Scenario: Retrieve my settings

- **WHEN** an authenticated user GETs `/v1/user/settings`
- **THEN** the system returns that user's settings with each value parsed according to its `type`

### Requirement: Upsert User Settings

The system SHALL let a user create or update their own settings in a single upsert operation.

#### Scenario: Upsert settings

- **WHEN** an authenticated user PATCHes `/v1/user/settings` with one or more key/value entries
- **THEN** the system creates missing settings and updates existing ones for that user
