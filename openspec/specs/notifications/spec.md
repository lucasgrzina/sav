# Notifications Specification

## Purpose

Deliver in-app notifications to users and track per-recipient read state. Each notification carries a JSON `payload` and is fanned out to recipient users through the `notification_recipients` pivot, which stores an individual `read_at`. Endpoints live under `/v1/notifications` and require authentication; a user only ever sees their own notifications.

## Requirements

### Requirement: List Notifications

The system SHALL return the authenticated user's notifications.

#### Scenario: List all notifications

- **WHEN** an authenticated user GETs `/v1/notifications`
- **THEN** the system returns that user's notifications with their individual read state

#### Scenario: Latest notifications

- **WHEN** an authenticated user GETs `/v1/notifications/latest`
- **THEN** the system returns the most recent notifications for that user (e.g. for a header dropdown)

### Requirement: Mark as Read

The system SHALL let a user mark notifications as read, individually or all at once.

#### Scenario: Mark one notification as read

- **WHEN** an authenticated user PATCHes `/v1/notifications/{guid}/read`
- **THEN** the system sets `read_at` on that user's recipient record for the notification

#### Scenario: Mark all as read

- **WHEN** an authenticated user PATCHes `/v1/notifications/read-all`
- **THEN** the system sets `read_at` on all of that user's unread notification recipient records
