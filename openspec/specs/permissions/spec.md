# Permissions Specification

## Purpose

Expose the catalog of authorization permissions so they can be assigned to roles. Permissions follow the `module.action` naming convention and are grouped by module for presentation. The endpoint lives under `/v1/permissions` and requires authentication.

## Requirements

### Requirement: List Permissions Grouped by Module

The system SHALL return all available permissions grouped by their module.

#### Scenario: Retrieve grouped permissions

- **WHEN** an authenticated user GETs `/v1/permissions`
- **THEN** the system returns permissions grouped by module, e.g. `[{ module, permissions: [{ guid, name }, ...] }, ...]`

#### Scenario: Naming convention

- **WHEN** permissions are returned
- **THEN** each permission `name` follows the `module.action` pattern and exposes a public `guid` rather than the internal id
