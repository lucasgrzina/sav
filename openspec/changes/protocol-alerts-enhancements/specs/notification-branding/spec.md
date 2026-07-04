## ADDED Requirements

### Requirement: Vet-Configurable Notification Signature

The system SHALL allow each vet (tenant) to configure a display signature — at minimum the veterinary practice name, optionally the professional's name — that is included in outgoing notification messages, so that recipients recognize which veterinary sent the alert.

#### Scenario: Vet sets a notification signature

- **WHEN** a vet saves a signature/branding value in their panel configuration
- **THEN** the system persists it associated with that vet and uses it as the default signature for all subsequent outgoing notifications

#### Scenario: Vet has not configured a signature

- **WHEN** a vet has no signature configured
- **THEN** the system falls back to the vet's registered name as the signature, so no notification is sent without any sender identification

### Requirement: Signature Applied to Protocol and Program Alerts

The system SHALL include the configured vet signature in notifications sent for `ProtocolAlert`-derived `Alert` records (`ProgramTaskNotification`, `ProgramCreatedNotification`, `ProgramCanceledNotification`).

#### Scenario: Program task notification includes signature

- **WHEN** the scheduler sends a `ProgramTaskNotification` for an `Alert` belonging to a `Program`
- **THEN** the rendered message includes the vet's configured signature

### Requirement: Signature Applied to Health Plan Alerts

The system SHALL include the configured vet signature in `HealthPlanMonthNotification` messages sent for the health calendar ("calendario sanitario").

#### Scenario: Monthly health plan reminder includes signature

- **WHEN** the scheduler sends a `HealthPlanMonthNotification` for a given month
- **THEN** the rendered message includes the vet's configured signature, consistent with the existing behavior described in the current health plan flow (message signed "con la firma de tu veterinaria")
