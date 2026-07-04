# Roles Specification

## Purpose

Manage authorization roles (Spatie-based) and the assignment of roles to users. Roles bundle permissions and are identified publicly by `guid`. Endpoints live under `/v1` and require authentication.

## Requirements

### Requirement: Role CRUD

The system SHALL provide create, read, update and delete operations for roles as a REST resource keyed by `guid`.

#### Scenario: List roles

- **WHEN** an authenticated user GETs `/v1/roles`
- **THEN** the system returns the roles with their permissions

#### Scenario: Create role

- **WHEN** an authenticated user POSTs `/v1/roles` with a unique `name` and a list of permission `guids`
- **THEN** the system creates the role and synchronizes its permissions by guid

#### Scenario: Show role

- **WHEN** an authenticated user GETs `/v1/roles/{guid}`
- **THEN** the system returns that role with its permissions

#### Scenario: Update role

- **WHEN** an authenticated user PUTs `/v1/roles/{guid}` with `name` and permission `guids`
- **THEN** the system updates the name and fully synchronizes (replaces) the role's permissions

### Requirement: Protected Roles

The system SHALL prevent deletion of the built-in `super-admin` and `admin` roles.

#### Scenario: Delete a custom role

- **WHEN** an authenticated user DELETEs `/v1/roles/{guid}` of a non-protected role
- **THEN** the system deletes the role

#### Scenario: Attempt to delete a protected role

- **WHEN** an authenticated user attempts to delete `super-admin` or `admin`
- **THEN** the system rejects the request

### Requirement: Assign Roles to a User

The system SHALL synchronize (replace, not merge) the roles assigned to a user.

#### Scenario: Sync user roles

- **WHEN** an authenticated user PUTs `/v1/users/{guid}/roles` with `roles: [guid, ...]`
- **THEN** the system replaces the user's role set with exactly the provided roles
