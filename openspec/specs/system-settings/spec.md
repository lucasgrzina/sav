# System Settings Specification

## Purpose

Manage global, platform-wide configuration values addressed by a stable `code`. Each setting carries a typed value (string, integer, boolean or json) that the system parses on read. Access is restricted to users holding the `system-settings.manage` permission; endpoints live under `/v1/system-settings`.

## Requirements

### Requirement: Manage-Only Access

The system SHALL restrict all system-settings endpoints to users with the `system-settings.manage` permission.

#### Scenario: Unauthorized access

- **WHEN** a user without `system-settings.manage` calls any `/v1/system-settings` endpoint
- **THEN** the system responds with HTTP 403

### Requirement: Read System Settings

The system SHALL expose system settings, listing all or fetching one by code, returning values parsed to their declared type.

#### Scenario: List settings

- **WHEN** an authorized user GETs `/v1/system-settings`
- **THEN** the system returns all settings with their parsed values

#### Scenario: Show a setting by code

- **WHEN** an authorized user GETs `/v1/system-settings/{code}`
- **THEN** the system returns that setting with its value parsed according to its `type` (integer, boolean, json, or raw string)

### Requirement: Update a System Setting

The system SHALL let an authorized user update a setting's value by code.

#### Scenario: Update a setting

- **WHEN** an authorized user PATCHes `/v1/system-settings/{code}` with a new value
- **THEN** the system persists the value for that setting code
