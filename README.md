# Jakawi FluentCRM Bridge — V1 (unreleased)

Independent repository; no Composer runtime dependency. Namespace `Jakawi\FluentCrmBridge`.
Legacy `jakawi-crm-bridge` remains untouched, active state unchanged, and its endpoint/table/event IDs are never consumed.

## Configuration and rollout

Define outside this repository in WordPress configuration:
- `JAKAWI_FLUENTCRM_BRIDGE_ENABLED` (strict boolean, default false)
- `JAKAWI_FLUENTCRM_BRIDGE_SECRET` (random shared secret of at least 32 bytes, no default)

Activation installs/updates only the technical event/idempotency table and plugin version, regardless of constants. It never provisions CRM entities or contacts. Schema provisioning is exclusively an explicit POST at Tools → Jakawi CRM Bridge → PROVISION CRM SCHEMA, guarded by manage_options and the WordPress nonce `jakawi_fluentcrm_provision`. There is no public provisioning REST route or configuration opt-in. Status reporting: NOT PROVISIONED, READY, DRIFT, CONFLICT, ERROR; counts only, no PII. No provisioning or live operations were performed. This is a clean pre-launch schema with no legacy migration.


## Contract

POST `/wp-json/jakawi-fluentcrm/v1/events`, JSON, maximum 65536 bytes.
Required keys: version (1), event_id (UUID), operation (CONTACT_UPSERT), occurred_at (RFC3339), source, contact, identity, lists_add, lists_remove, tags_add, tags_remove, fields, marketing.
`source`: type, id, optional first_attribution / last_attribution clocks {occurred_at,id}.
`contact`: email, optional name. `identity`: optional jakawi_user_id, provider_contact_id.
`marketing`: action GRANT / REVOKE / UNCHANGED. Unknown keys/entities rejected.
All nine first/last attribution fields travel together from one touch. First snapshot freezes; latest uses touch timestamp plus numeric ID. Domain snapshots use occurred_at plus event UUID; activity uses maximum timestamp. Bridge metadata stores clocks without payload. For an existing first snapshot, missing values are never filled from another touch.

## Security and persistence

Headers X-Jakawi-Timestamp, X-Jakawi-Event-Id, X-Jakawi-Signature.
HMAC SHA256 hex over `timestamp + "\n" + event_id + "\n" + raw_body`; constant-time comparison; ±300 seconds. Authenticate before storage or CRM work. HTTPS required by Laravel. No PII, bodies, secrets, or signatures logged.
Atomic unique event reservation and transaction, serialized writer lock, SHA256 body hash, sanitized results and provider ID only. Same successful body returns original success; altered body 409; active writer returns 503 Retry-After; failed transient attempt can reserve again. Named lock release on connection death permits recovery; CRM and event writes must use transactional InnoDB tables on the same WordPress connection. FluentCRM hooks can have external side effects outside SQL rollback; rollout must audit installed hooks.

## Schema and identity

Lists: leads → JAKAWI — Leads; users → JAKAWI — Usuarios; partners → JAKAWI — Partners. Partner applicants never imply confirmed partners. Slugs are namespaced jakawi-v1-*.
Tags: jakawi-lead, jakawi-user, partner-applicant, program-applicant, jakawi-partner, membership-requested, member-active, member-expired, benefit-redeemer, experience-reserver, challenge-participant, unlock-participant, source-meta, source-tiktok, source-google.
CRM_SCHEMA_VERSION = 1. Exact counts: 3 lists, 15 tags, 32 custom fields.
Fields: jakawi_user_id, jakawi_city, jakawi_lifecycle_stage, membership_status, membership_started_at, membership_expires_at, lead_source, lead_form, first_acquisition_provider, first_utm_source, first_utm_medium, first_utm_campaign, first_utm_content, first_utm_term, first_campaign_key, first_landing_slug, first_touch_at, last_acquisition_provider, last_utm_source, last_utm_medium, last_utm_campaign, last_utm_content, last_utm_term, last_campaign_key, last_landing_slug, last_touch_at, signup_at, last_activity_type, last_activity_at, marketing_opt_in, marketing_opt_in_at, marketing_opt_in_source.
Text fields preserve enumerations/nullable snapshots; user ID is number, timestamps date_time. Product ID never maps to FluentCRM native user_id.
Resolve provider ID, then custom jakawi_user_id, then normalized email. Incompatible IDs or occupied new email return 409 without merging. Existing unrelated fields remain intact.

Single opt-in: new GRANT subscribed; new REVOKE/UNCHANGED transactional. Existing explicit REVOKE unsubscribed; transactional GRANT subscribed. Unsubscribed/bounced/complained/spammed are preserved. No double opt-in.

## Validation

Run tests/run.php with existing Docker PHP 8.4 and this directory mounted read-only. Do not invoke host PHP. No WordPress boot required for pure tests.

### Minimal envelope

```json
{
  "version": 1,
  "event_id": "9fac1662-b42c-4de7-928a-5c19bfaec434",
  "operation": "CONTACT_UPSERT",
  "occurred_at": "2026-10-09T18:00:00.123456Z",
  "source": {"type": "program_application_submitted", "id": "1"},
  "contact": {"email": "example@example.test", "name": "Example"},
  "identity": {"jakawi_user_id": "1", "provider_contact_id": null},
  "lists_add": ["users"],
  "lists_remove": ["leads"],
  "tags_add": ["jakawi-user", "program-applicant"],
  "tags_remove": [],
  "fields": {"jakawi_user_id": "1", "jakawi_lifecycle_stage": "user"},
  "marketing": {"action": "UNCHANGED"}
}
```

Explicit GRANT/REVOKE require matching boolean marketing_opt_in and RFC3339 marketing_opt_in_at in fields. Runtime verifies the saved entity map against current CRM schema without provisioning on requests. Guest submissions to an already linked user contact preserve its product identity, name and domain/list state. Database error printing is suppressed during authenticated processing and restored afterward; errors returned/stored by this plugin are fixed codes only.

## Marketing projection boundaries

JAKAWI retains complete event history; FluentCRM receives only marketing segmentation. No per-view records, activities or tags. No click IDs (fbclid/gclid/ttclid), raw metadata, fingerprint or full query strings. landing_view and landing_cta_click never enqueue CRM delivery.

FIRST selects occurred_at ASC, id ASC and freezes one coherent touch, including nullable content/term. Never fill holes from another touch. LATEST selects occurred_at DESC, id DESC and updates only for a newer touch clock. source-meta/source-tiktok/source-google derive only from frozen FIRST; NONE has no source tag. LATEST provider stays in fields. lead_source/lead_form are FIRST_WRITE; form is a stable surface key, never a URL. signup_at is first-write. last_activity_type and last_activity_at update together only for a strictly newer activity timestamp.

Canonical activity values: lead_submitted, signup_completed, profile_updated, marketing_preference_changed, membership_purchase_requested, membership_activated, benefit_redeemed, experience_reserved, challenge_joined, unlock_committed, partner_application_submitted, program_application_submitted. Membership state updates can project domain status without introducing another activity value.

Register → users / jakawi-user; promotion removes leads. Partner application with email → leads / jakawi-lead / partner-applicant; never confirmed partner. Program → existing user / program-applicant. Membership request → membership-requested; activation removes that tag and member-expired, adds member-active; expiration removes member-active and adds member-expired. Domain membership fields remain authoritative. Benefit/Experience/Challenge/Unlock → benefit-redeemer/experience-reserver/challenge-participant/unlock-participant. Profile → identity/city/lifecycle/preference. A confirmed product partner relation → partners / jakawi-partner.

Consent checkbox: “Quiero recibir novedades, beneficios y experiencias de JAKAWI por email.” Initially checked on new register/partner forms; backend missing/unchecked=false. New true=subscribed, new false=transactional; explicit preference false=unsubscribed; ordinary event=UNCHANGED. No double opt-in. Suppressions unsubscribed/bounced/complained/spammed are always preserved.

## Contract finalization validation — 2026-10-09

Docker PHP 8.4.26 only. Bridge: 112 assertions passed, all eight PHP files passed syntax checks. Laravel CRM focused: 19 tests / 114 assertions passed. Exactly one full Laravel suite: 524 passed, 1 failed, 6660 assertions, 131.12 seconds. The sole failure is the allowed CampaignTest::test_admin_can_create_campaign_and_non_admin_cannot baseline (403 versus expected redirect). No additional regression. Existing test container and isolated jakawi_test PostgreSQL were used, with HTTP fakes; no CRM runtime in Docker.

Both repositories passed git diff --check, including whitespace checks for untracked files. No frontend source changed during this task; no Docker/Vite build. Live mutations: NONE. No provisioning, contacts, real secrets, webhook, plugin activation/deactivation, sync enablement, production migration, deploy, commit or push. Ready to version: SÍ.
