# Auth Specification

## Purpose

Provide account registration, credential-based authentication, email verification, password recovery, and staff invitation acceptance for the SAV platform. Authentication is stateless via Laravel Sanctum bearer tokens, and all endpoints are versioned under `/v1/auth`.

## Requirements

### Requirement: User Registration

The system SHALL allow a new user to self-register with name, email and password, creating an unverified account that cannot log in until its email is verified.

#### Scenario: Successful registration

- **WHEN** a client POSTs `first_name`, `last_name`, `email` and a valid `password` to `/v1/auth/register`
- **THEN** the system creates a `User` with `email_verified_at = null`, generates a 6-digit verification code, an expiry and a verification link token, sends the verification email, and responds with the user `guid` and `email`

#### Scenario: Password policy enforcement

- **WHEN** the submitted password does not meet the policy (8–12 characters with at least one uppercase letter, one number and one symbol)
- **THEN** the system rejects the request with a validation error and does not create the user

#### Scenario: Duplicate email

- **WHEN** the submitted email already belongs to an existing user
- **THEN** the system rejects the request with a validation error

#### Scenario: Rate limiting

- **WHEN** more than 5 registration requests are made from the same client within one minute
- **THEN** the system responds with HTTP 429 Too Many Requests

### Requirement: Email Verification

The system SHALL verify a user's email through a 6-digit code that expires after a configurable window (default 10 minutes).

#### Scenario: Verify with valid code

- **WHEN** a client POSTs a matching, non-expired `code` for a user `guid` to `/v1/auth/verify-account/verify-code`
- **THEN** the system sets `email_verified_at = now()` and clears the verification code, expiry and link token

#### Scenario: Verify with invalid or expired code

- **WHEN** the code is wrong or its expiry has passed
- **THEN** the system rejects the request with an error and leaves the account unverified

#### Scenario: Resend verification code

- **WHEN** a client POSTs a user `guid` to `/v1/auth/verify-account/resend-code`
- **THEN** the system regenerates the code and expiry and resends the verification email

### Requirement: Login

The system SHALL authenticate users by email and password, issuing a Sanctum access token only for verified, unlocked accounts.

#### Scenario: Successful login

- **WHEN** a verified, unlocked user submits correct credentials to `/v1/auth/login`
- **THEN** the system resets `failed_login_attempts`, updates `last_login_at`, issues a Sanctum access token, and returns `{ access_token, user, must_verify_account: false }`

#### Scenario: Unverified account

- **WHEN** a user with a valid password has not verified their email
- **THEN** the system returns `{ must_verify_account: true, user: { guid, email } }` instead of a token

#### Scenario: Wrong password increments lockout counter

- **WHEN** a user submits an incorrect password
- **THEN** the system increments `failed_login_attempts`, and upon reaching 3 failed attempts sets `locked_at = now()`

#### Scenario: Locked account

- **WHEN** a user whose account is locked attempts to log in
- **THEN** the system responds with HTTP 403 and does not issue a token

#### Scenario: Login rate limiting

- **WHEN** the configured login throttle is exceeded
- **THEN** the system responds with HTTP 429 Too Many Requests

### Requirement: Session Termination and Profile

The system SHALL expose the authenticated user's profile and allow token revocation.

#### Scenario: Get authenticated profile

- **WHEN** an authenticated user GETs `/v1/auth/profile`
- **THEN** the system returns the current user's data including roles and profile memberships

#### Scenario: Logout

- **WHEN** an authenticated user POSTs `/v1/auth/logout`
- **THEN** the system revokes the current access token

### Requirement: Password Recovery

The system SHALL provide a three-step password recovery flow: request a code, verify the code, then reset the password.

#### Scenario: Request recovery code

- **WHEN** a client POSTs an `email` to `/v1/auth/forgot-password/verify-email`
- **THEN** the system creates a `password_resets` record with a token and 6-digit code marked `used = false` and sends the recovery email

#### Scenario: Verify recovery code

- **WHEN** a client POSTs a matching unused `code` for the `email` to `/v1/auth/forgot-password/verify-code`
- **THEN** the system marks the record `used = true`

#### Scenario: Reset password

- **WHEN** a client POSTs a valid `token`, new `password` and `password_confirmation` to `/v1/auth/forgot-password/reset-password`
- **THEN** the system updates the password, sets `password_changed_at`, and invalidates the reset token

#### Scenario: Recovery rate limiting

- **WHEN** more than 5 forgot-password requests are made within one minute
- **THEN** the system responds with HTTP 429 Too Many Requests

### Requirement: Invitation Acceptance

The system SHALL allow an invited staff member to accept their invitation and activate their account.

#### Scenario: Accept invitation

- **WHEN** a client POSTs valid invitation credentials to `/v1/auth/invitation/accept`
- **THEN** the system activates the associated user account and profile membership

#### Scenario: Invitation rate limiting

- **WHEN** more than 5 invitation-accept requests are made within one minute
- **THEN** the system responds with HTTP 429 Too Many Requests
