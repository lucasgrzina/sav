# Support Messages Specification

## Purpose

Provide a support ticketing channel where users can open messages to the platform team, exchange replies, and have tickets closed. Each message has a subject, body, status, priority and category, a sender, and an optional closer. Endpoints live under `/v1/support-messages` and require authentication.

## Requirements

### Requirement: Create and List Support Messages

The system SHALL let a user create support messages and list them.

#### Scenario: Create a support message

- **WHEN** an authenticated user POSTs `subject`, `body` (and optionally `priority`, `category`) to `/v1/support-messages`
- **THEN** the system creates the message with the current user as sender and an open status

#### Scenario: List support messages

- **WHEN** an authenticated user GETs `/v1/support-messages`
- **THEN** the system returns the support messages visible to that user

### Requirement: View and Delete Support Message

The system SHALL expose a single support message and allow its deletion.

#### Scenario: Show a support message

- **WHEN** an authenticated user GETs `/v1/support-messages/{guid}`
- **THEN** the system returns the message with its replies

#### Scenario: Delete a support message

- **WHEN** an authorized user DELETEs `/v1/support-messages/{guid}`
- **THEN** the system removes the message

### Requirement: Replies

The system SHALL let participants add replies to a support message.

#### Scenario: Add a reply

- **WHEN** an authenticated user POSTs `/v1/support-messages/{guid}/replies` with a body
- **THEN** the system appends the reply to the message thread

### Requirement: Close a Support Message

The system SHALL let a support message be closed, recording who closed it and when.

#### Scenario: Close a message

- **WHEN** an authorized user PATCHes `/v1/support-messages/{guid}/close`
- **THEN** the system sets the status to closed, records `closed_at` and `closed_by`
