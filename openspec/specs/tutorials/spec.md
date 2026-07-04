# Tutorials Specification

## Purpose

Manage tutorial resources (help/onboarding content) that reference an external source and code, with a display order. Endpoints live under `/v1/tutorials` and require authentication; tutorials are identified publicly by `guid`.

## Requirements

### Requirement: List Tutorials

The system SHALL return the available tutorials.

#### Scenario: Retrieve tutorials

- **WHEN** an authenticated user GETs `/v1/tutorials`
- **THEN** the system returns the tutorials, ordered by their `order`

### Requirement: Manage Tutorials

The system SHALL let authorized users create, update and delete tutorials.

#### Scenario: Create a tutorial

- **WHEN** an authorized user POSTs `/v1/tutorials` with `source`, `code` and `order`
- **THEN** the system creates the tutorial

#### Scenario: Update a tutorial

- **WHEN** an authorized user PUTs `/v1/tutorials/{guid}`
- **THEN** the system updates that tutorial

#### Scenario: Delete a tutorial

- **WHEN** an authorized user DELETEs `/v1/tutorials/{guid}`
- **THEN** the system removes that tutorial
