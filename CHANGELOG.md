# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Update tickets with `PATCH /tickets/{id}`. Only the fields sent are written, so a caller can
  change one attribute without clearing the rest, and `status` is given as `NEW`/`INPROGRESS`/`DONE`
  rather than a per-project id.
- Read a single ticket with `GET /tickets/{id}`.
- Log and read time entries via `POST` and `GET /timesheets`.
- Read and add ticket comments via `GET` and `POST /tickets/{id}/comments`.
- List ticket attachments with `GET /tickets/{id}/files`. Metadata only — never file contents.
- List granted projects with `GET /projects`, and read a project's completion percentage and dates
  with `GET /projects/{id}/progress`.
- List a project's status labels and their types with `GET /projects/{id}/statuses`, so clients can
  map statuses without hardcoding ids that differ per project.
- List milestones with `GET /milestones`.

All new endpoints enforce the calling key's operation and project grants, and no core events or
notifications fire on the write paths — matching the existing `POST /tickets` behaviour.

### Fixed

- `POST /timesheets` returns `409` with a readable message when time is already logged for that
  person, ticket, date and kind. It previously surfaced Leantime's unique-constraint violation
  as a `500` carrying the failed SQL statement.
