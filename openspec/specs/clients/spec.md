# Clients Specification

## Purpose

Manage clients (livestock owners/producers) and their establishments within the SAV platform. A client can be linked to one or more vets through the `client_vet` relationship. Covers the SuperAdmin panel (any client and its links, establishments and staff) and the tenant panel (a vet managing its own clients). Establishments belong to a client and represent physical fields/farms. Endpoints live under `/v1/admin/clients` (admin) and `/v1/vets/{vet}/clients` (tenant, guarded by `vet.tenant`), gated by `clients.*` and `establishments.*` permissions.

## Requirements

### Requirement: Admin Client Management

The system SHALL let SuperAdmins list, look up, view, create and update any client, gated by `clients.*` permissions.

#### Scenario: List and create clients

- **WHEN** a user with `clients.read` / `clients.create` GETs or POSTs `/v1/admin/clients`
- **THEN** the system returns the clients or creates a new one

#### Scenario: Lookup client

- **WHEN** a user with `clients.read` GETs `/v1/admin/clients/lookup`
- **THEN** the system returns matching existing clients (route resolved before `/{guid}` to avoid collision)

#### Scenario: Show and update client

- **WHEN** a user with the appropriate permission GETs `/v1/admin/clients/{guid}` or PUTs updated data
- **THEN** the system returns or updates that client

### Requirement: Client–Vet Linking (Admin)

The system SHALL let admins link and unlink a client to/from a vet without the tenant middleware.

#### Scenario: Link a client to a vet

- **WHEN** a user with `clients.create` POSTs `/v1/admin/clients/{clientGuid}/vets`
- **THEN** the system creates the client–vet link

#### Scenario: Unlink a client from a vet

- **WHEN** a user with `clients.delete` DELETEs `/v1/admin/clients/{clientGuid}/vets/{vetGuid}`
- **THEN** the system removes the client–vet link

### Requirement: Admin Establishment Management

The system SHALL let admins manage the establishments of any client, gated by `establishments.*` permissions.

#### Scenario: Manage establishments

- **WHEN** a user with the corresponding `establishments.*` permission calls the endpoints under `/v1/admin/clients/{guid}/establishments`
- **THEN** the system lists, creates, updates or deletes establishments (including geolocation `latitude`/`longitude`) belonging to that client

### Requirement: Admin Client Staff Management

The system SHALL let admins manage a client's staff memberships, gated by `clients.staff.*` permissions.

#### Scenario: Manage client staff from admin panel

- **WHEN** a user with the corresponding `clients.staff.*` permission calls the endpoints under `/v1/admin/clients/{guid}/staff`
- **THEN** the system lists, creates, shows, updates, re-roles or deletes client staff memberships

### Requirement: Tenant Client Management

The system SHALL let a vet tenant manage its own clients, gated by `clients.*` permissions and the `vet.tenant` middleware.

#### Scenario: CRUD own clients

- **WHEN** an authorized member calls the endpoints under `/v1/vets/{vet}/clients`
- **THEN** the system lists, creates, looks up, shows, updates or deletes clients scoped to that vet

#### Scenario: Link an existing client to the tenant

- **WHEN** a member with `clients.create` POSTs `/v1/vets/{vet}/clients/{guid}/link`
- **THEN** the system links the existing client to the current vet

### Requirement: Client Owners

The system SHALL let a tenant manage the owners of a client.

#### Scenario: List and add owners

- **WHEN** a member with `clients.owners.read` / `clients.owners.create` GETs or POSTs `/v1/vets/{vet}/clients/{guid}/owners`
- **THEN** the system returns the client's owners or adds a new owner

### Requirement: Tenant Client Contacts and Establishments

The system SHALL let a tenant manage the contacts and establishments of its clients.

#### Scenario: Manage client contacts

- **WHEN** a member calls the CRUD endpoints under `/v1/vets/{vet}/clients/{client}/contacts`
- **THEN** the system lists, creates, updates or deletes contacts attached to that client

#### Scenario: Manage client establishments

- **WHEN** a member with `establishments.*` permissions calls the endpoints under `/v1/vets/{vet}/clients/{client}/establishments`
- **THEN** the system lists, creates, updates or deletes that client's establishments

### Requirement: Tenant Client Staff Management

The system SHALL let a tenant manage a client's staff, including inviting existing users, creating new users, re-roling and blocking.

#### Scenario: Manage client staff from tenant panel

- **WHEN** a member with `clients.staff.*` permissions calls the endpoints under `/v1/vets/{vet}/clients/{client}/staff` (index, store, `lookup`, `new-user`, show, update, delete, `role`, `toggle-block`)
- **THEN** the system manages that client's staff memberships, with static routes (`lookup`, `new-user`) resolved before dynamic `{guid}` routes
