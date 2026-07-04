# Countries Specification

## Purpose

Provide reference data for countries and their associated document types, used when registering vets and clients across the platform's supported markets. Endpoints live under `/v1/countries` and require authentication.

## Requirements

### Requirement: List Countries

The system SHALL return the available countries.

#### Scenario: Retrieve countries

- **WHEN** an authenticated user GETs `/v1/countries`
- **THEN** the system returns the list of countries

### Requirement: List Document Types by Country

The system SHALL return the document types associated with a given country.

#### Scenario: Retrieve a country's document types

- **WHEN** an authenticated user GETs `/v1/countries/{guid}/document-types`
- **THEN** the system returns the document types belonging to that country
