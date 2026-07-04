# Exports Specification

## Purpose

Generate downloadable data exports asynchronously. A user requests an export of a supported data type in a chosen format; the system tracks its status through a lifecycle and produces a file that can be downloaded until it expires. Endpoints live under `/v1/exports` and require authentication; exports are scoped to the requesting user and identified by `guid`.

## Requirements

### Requirement: Supported Types and Formats

The system SHALL restrict exports to supported data types and file formats.

#### Scenario: Allowed export type

- **WHEN** an export is requested
- **THEN** its `type` MUST be one of the supported types (`users`, `roles`)

#### Scenario: Allowed export format

- **WHEN** an export is requested
- **THEN** its `format` MUST be one of `xlsx`, `csv`, `txt` or `pdf`, and the generated file uses the matching MIME type

### Requirement: Request and List Exports

The system SHALL let a user create an export request and list their exports.

#### Scenario: Create an export

- **WHEN** an authenticated user POSTs `/v1/exports` with a `type`, `format` and optional `filters`/`columns`
- **THEN** the system creates an export record in `pending` status and queues its generation

#### Scenario: List exports

- **WHEN** an authenticated user GETs `/v1/exports`
- **THEN** the system returns that user's exports with their current status

### Requirement: Export Status Lifecycle

The system SHALL track each export through a status lifecycle.

#### Scenario: Status transitions

- **WHEN** an export is processed
- **THEN** its `status` moves through `pending` → `processing` → `completed`, or to `failed` with an `error_message` if generation fails

#### Scenario: Show an export

- **WHEN** an authenticated user GETs `/v1/exports/{guid}`
- **THEN** the system returns that export's status and metadata

### Requirement: Download Export

The system SHALL let a user download a completed export until it expires.

#### Scenario: Download a completed export

- **WHEN** an authenticated user GETs `/v1/exports/{guid}/download` for a completed, non-expired export
- **THEN** the system streams the generated file with the format's MIME type

#### Scenario: Expired export

- **WHEN** the export's `expires_at` is in the past
- **THEN** the export is no longer downloadable
