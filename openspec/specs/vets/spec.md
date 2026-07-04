# Vets Specification

## Purpose

Manage veterinary organizations (vets), which are the tenants of the multi-tenant SAV platform. Covers the SuperAdmin panel (validation, suspension, oversight of any vet and its staff) and the tenant panel (a vet managing its own profile, staff and contacts). A vet is considered an active tenant only when it is validated and not suspended. Endpoints live under `/v1/admin/vets` (admin) and `/v1/vets/{vet}` (tenant, guarded by the `vet.tenant` middleware).

## Requirements

### Requirement: Admin Vet Management

The system SHALL let SuperAdmins list, view, create and update vets, gated by `vets.*` permissions.

#### Scenario: List vets

- **WHEN** a user with `vets.read` GETs `/v1/admin/vets`
- **THEN** the system returns the vets

#### Scenario: Create vet

- **WHEN** a user with `vets.create` POSTs vet data (name, country, document type, tax id, registration number) to `/v1/admin/vets`
- **THEN** the system creates the vet

#### Scenario: Show and update vet

- **WHEN** a user with the appropriate permission GETs `/v1/admin/vets/{guid}` or PUTs updated data
- **THEN** the system returns or updates that vet

#### Scenario: Permission enforcement

- **WHEN** a user lacking the required `vets.*` permission calls an admin vet endpoint
- **THEN** the system responds with HTTP 403

### Requirement: Vet Validation Lifecycle

The system SHALL let authorized admins validate, suspend and unsuspend a vet, controlling whether it is an active tenant.

#### Scenario: Validate a vet

- **WHEN** a user with `vets.validate` PATCHes `/v1/admin/vets/{guid}/validate`
- **THEN** the system sets `validated_at` and records the validating admin

#### Scenario: Suspend a vet

- **WHEN** a user with `vets.validate` PATCHes `/v1/admin/vets/{guid}/suspend`
- **THEN** the system sets `suspended_at`, and the vet is no longer an active tenant

#### Scenario: Unsuspend a vet

- **WHEN** a user with `vets.validate` PATCHes `/v1/admin/vets/{guid}/unsuspend`
- **THEN** the system clears `suspended_at`, restoring the vet as an active tenant if validated

#### Scenario: Active tenant definition

- **WHEN** the system evaluates whether a vet is an active tenant
- **THEN** it is active only when `validated_at` is set and `suspended_at` is null

### Requirement: Admin Oversight of Vet Staff and Clients

The system SHALL let admins view a vet's clients and manage its staff.

#### Scenario: List a vet's clients

- **WHEN** a user with `clients.read` GETs `/v1/admin/vets/{guid}/clients`
- **THEN** the system returns the clients linked to that vet

#### Scenario: Manage a vet's staff from the admin panel

- **WHEN** a user with the corresponding `vets.staff.*` permission calls the admin staff endpoints under `/v1/admin/vets/{guid}/staff`
- **THEN** the system lists, creates, shows, updates, changes the role of, or deletes staff profile memberships for that vet

### Requirement: Tenant Vet Self-Management

The system SHALL let a vet tenant view and update its own organization profile.

#### Scenario: Show own vet

- **WHEN** an authenticated member GETs `/v1/vets/{vet}` for a vet they belong to
- **THEN** the system returns that vet's profile including branding fields (logo, PDF title/subtitle)

#### Scenario: Update own vet

- **WHEN** an authorized member PUTs `/v1/vets/{vet}`
- **THEN** the system updates the vet's profile

#### Scenario: Tenant isolation

- **WHEN** a user who does not belong to the vet accesses `/v1/vets/{vet}` endpoints
- **THEN** the `vet.tenant` middleware blocks the request

### Requirement: Tenant Staff Management

The system SHALL let a vet tenant manage its staff memberships, including inviting existing users, creating new users, changing roles, and blocking.

#### Scenario: List and create staff

- **WHEN** an authorized member GETs or POSTs `/v1/vets/{vet}/staff`
- **THEN** the system lists the vet's staff or creates a new membership

#### Scenario: Lookup existing user for staff

- **WHEN** a member GETs `/v1/vets/{vet}/staff/lookup`
- **THEN** the system returns matching existing users that can be assigned as staff

#### Scenario: Create and assign a new user

- **WHEN** a member POSTs `/v1/vets/{vet}/staff/new-user`
- **THEN** the system creates a new user and assigns them to the vet

#### Scenario: Update, change role, delete and block staff

- **WHEN** a member calls the show/update/delete/`role`/`toggle-block` endpoints under `/v1/vets/{vet}/staff/{guid}`
- **THEN** the system updates, removes, re-roles or blocks/unblocks that staff membership

### Requirement: Vet and Staff Contacts

The system SHALL let a vet manage contacts for the organization and for individual staff members.

#### Scenario: Manage vet contacts

- **WHEN** a member calls the CRUD endpoints under `/v1/vets/{vet}/contacts`
- **THEN** the system lists, creates, updates or deletes contacts attached to the vet

#### Scenario: Manage staff member contacts

- **WHEN** a member calls the CRUD endpoints under `/v1/vets/{vet}/staff/{profile}/contacts`
- **THEN** the system manages contacts attached to that staff profile

### Requirement: My Vet Profile

The system SHALL let the authenticated member view and update their own profile within the current vet tenant.

#### Scenario: View and update my profile

- **WHEN** the authenticated member GETs or PUTs `/v1/vets/{vet}/my-profile`
- **THEN** the system returns or updates that member's own profile within the vet
