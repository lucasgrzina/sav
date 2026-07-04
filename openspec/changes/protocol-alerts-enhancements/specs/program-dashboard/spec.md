## ADDED Requirements

### Requirement: Consolidated Active Programs View

The system SHALL provide the vet with a consolidated view of all active `Program` records across establishments and clients, so that a vet managing several establishments in parallel can see, at a glance, which protocol step is due on each of them.

#### Scenario: Vet opens the consolidated dashboard

- **WHEN** an authenticated vet requests the consolidated programs dashboard
- **THEN** the system returns all non-cancelled `Program` records for that vet, grouped by establishment and client

#### Scenario: Dashboard shows current step per program

- **WHEN** the consolidated dashboard is rendered for a given program
- **THEN** the system shows the program's current computed state (Pending / In progress / Completed, per the existing state calculation) and the next upcoming task/alert date

### Requirement: Dashboard Respects Tenant and Role Scope

The system SHALL restrict the consolidated dashboard to programs belonging to the authenticated vet's tenant, and SHALL NOT expose it to client-side roles.

#### Scenario: Vet-assistant accesses the dashboard

- **WHEN** a user with role `vet` or `vet-assistant` requests the dashboard
- **THEN** the system returns only programs scoped to their own vet tenant

#### Scenario: Client role attempts to access the dashboard

- **WHEN** a user with a client-side role (`client-owner`, `client-manager`, `client-administrative`) requests the dashboard endpoint
- **THEN** the system denies access, since this view is scoped to vet-side roles managing multiple establishments
