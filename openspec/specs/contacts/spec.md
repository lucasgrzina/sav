# Contacts Specification

## Purpose

Provide a polymorphic contact mechanism shared across the platform's entities (vets, clients and staff profiles). A contact captures a communication channel (email, phone, WhatsApp) with an optional label, a primary flag, and an alerts-opt-in flag used to decide notification recipients. Contacts are always managed nested under their owning entity and identified by `guid`.

## Requirements

### Requirement: Contact Channel Types

The system SHALL restrict a contact's type to a defined set of communication channels.

#### Scenario: Allowed channel types

- **WHEN** a contact is created or updated
- **THEN** its `type` MUST be one of `email`, `phone` or `whatsapp`

### Requirement: Polymorphic Contact Ownership

The system SHALL attach contacts polymorphically to any contactable entity (vet, client, or staff profile) via the nested contact endpoints of that entity.

#### Scenario: Create a contact for an entity

- **WHEN** an authorized member POSTs a contact to the entity's nested `contacts` collection
- **THEN** the system creates a contact linked to that contactable entity with the given `type`, `label` and `value`

#### Scenario: List, update and delete contacts

- **WHEN** an authorized member GETs, PUTs `/{guid}` or DELETEs `/{guid}` on the entity's nested contacts
- **THEN** the system returns, updates or removes the corresponding contact of that entity

### Requirement: Primary and Alert Flags

The system SHALL let each contact carry a primary flag and an alerts-opt-in flag.

#### Scenario: Mark contact for alerts

- **WHEN** a contact is saved with `use_for_alerts = true`
- **THEN** the system includes that contact when resolving recipients for alert notifications

#### Scenario: Primary contact flag

- **WHEN** a contact is saved with `is_primary = true`
- **THEN** the system records it as the primary contact for its channel
