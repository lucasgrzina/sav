## ADDED Requirements

### Requirement: Administrative Protocol Alerts

The system SHALL allow a `ProtocolTask`/`ProtocolAlert` to target the `vet-administrative` role with a `days_offset` positive relative to the program's `target_date`, so that administrative follow-up (e.g. billing or collection reminders) is generated automatically as part of a `Program`'s alert schedule, using the same alert engine as any other protocol task.

#### Scenario: Vet configures an administrative alert on a protocol

- **WHEN** a vet creates or edits a `ProtocolAlert` with `roles` including `vet-administrative` and a `days_offset` after the technique's target date
- **THEN** the system persists the alert as part of the protocol template, available to be instantiated by any `Program` created from that protocol

#### Scenario: Administrative alert is instantiated when a program is created

- **WHEN** a `Program` is created from a protocol that has one or more `ProtocolAlert` records targeting `vet-administrative`
- **THEN** `UpsertProgramAlertsAction` calculates `send_at` for each of those alerts using the existing Before/After `days_offset` logic and creates the corresponding `Alert` records with recipients limited to users holding the `vet-administrative` role for that vet

#### Scenario: Administrative alert respects the same past-date skip rule

- **WHEN** the calculated `send_at` for an administrative alert falls before the start of the current day
- **THEN** the system does not create that `Alert`, consistent with the existing rule for all other `ProtocolAlert` instances

### Requirement: Field Role Receives Maximum Alert Volume by Default

The system SHALL treat the `client-manager` role as the default recipient of protocol task alerts unless a specific `ProtocolAlert.roles` configuration explicitly excludes it, so that the person executing tasks on the ground ("encargado de campo") receives the broadest visibility into the program's schedule.

#### Scenario: Protocol alert without explicit role restriction

- **WHEN** a `ProtocolAlert` has an empty or null `roles` field
- **THEN** all assigned program managers, including any user with the `client-manager` role, receive the alert

#### Scenario: Vet excludes client-manager from a specific alert

- **WHEN** a vet sets `ProtocolAlert.roles` to a list that does not include `client-manager`
- **THEN** users with only the `client-manager` role do not receive that specific alert, while still receiving all other alerts on the program that are not similarly restricted

### Requirement: Multi-Recipient Alert Delivery Reliability

The system SHALL mark an `Alert` as `delivered_at` only after attempting delivery to all of its assigned recipients, so that alerts with multiple recipients (e.g. `client-manager` and `vet-administrative` on the same task) are not silently skipped for recipients processed after the first.

#### Scenario: Alert with multiple recipients is sent to all of them

- **WHEN** the scheduler processes a pending `Alert` that has more than one recipient in `alert_user`
- **THEN** every recipient receives the notification via their configured channel before the alert's `delivered_at` is set

#### Scenario: Partial delivery failure does not block delivery to other recipients

- **WHEN** sending to one recipient of a multi-recipient alert fails
- **THEN** the system still attempts delivery to the remaining recipients before marking the alert as delivered, and logs the individual failure
