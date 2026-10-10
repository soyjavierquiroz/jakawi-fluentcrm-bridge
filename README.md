# Jakawi FluentCRM Bridge — 1.0.1

Independent repository; no Composer runtime dependency. Namespace `Jakawi\FluentCrmBridge`.
Purpose: secure Laravel → FluentCRM contact projection bridge. Current production uses WordPress + FluentCRM on CyberPanel host runtime, not Docker. Operational reference: 2026-10-09.

## Migration / history

Legacy `jakawi-crm-bridge` is obsolete/inactive. Its endpoint/table/event IDs are not consumed by this bridge. No automatic legacy migration/backfill or contact merge is performed.

## Configuration and rollout

Define outside this repository in WordPress configuration:
- `JAKAWI_FLUENTCRM_BRIDGE_ENABLED` (strict boolean, default false)
- `JAKAWI_FLUENTCRM_BRIDGE_SECRET` (random shared secret of at least 32 bytes, no default)

Activation installs/updates only the technical event/idempotency table and plugin version, regardless of constants. It never provisions CRM entities or contacts. Schema provisioning is exclusively an explicit POST at Tools → Jakawi CRM Bridge → PROVISION / REPAIR CRM SCHEMA, guarded by manage_options and the WordPress nonce `jakawi_fluentcrm_provision`. There is no public provisioning REST route or configuration opt-in. Status reporting: NOT PROVISIONED, READY, DRIFT, CONFLICT, ERROR; counts only, no PII. Current schema contract is V1, READY **3 lists / 15 tags / 32 custom fields** when live diagnostics confirm compatibility. Documentation updates do not execute provisioning. No legacy migration is automatic.


## Contract

Registered plugin route: `jakawi-fluentcrm/v1/events`, POST JSON, maximum **65536 bytes**.

Laravel supports exactly these production HTTPS forms:

- Canonical: `https://crm.jakawi.com/wp-json/jakawi-fluentcrm/v1/events`
- Current fallback: `https://crm.jakawi.com/?rest_route=/jakawi-fluentcrm/v1/events`

Current server routing returns 404 for the pretty `/wp-json` form, so production uses the fallback without redirects. Both address the same registered plugin route; fallback is a host routing compatibility choice. No extra queries, alternate hosts or arbitrary endpoint URLs are allowed by Laravel.
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
32 fields grouped (LATEST uses last_* names):

| Group | Exact keys |
| --- | --- |
| Identity / lifecycle (14) | jakawi_user_id, jakawi_city, jakawi_lifecycle_stage, membership_status, membership_started_at, membership_expires_at, lead_source, lead_form, signup_at, last_activity_type, last_activity_at, marketing_opt_in, marketing_opt_in_at, marketing_opt_in_source |
| FIRST attribution (9) | first_acquisition_provider, first_utm_source, first_utm_medium, first_utm_campaign, first_utm_content, first_utm_term, first_campaign_key, first_landing_slug, first_touch_at |
| LATEST attribution (9) | last_acquisition_provider, last_utm_source, last_utm_medium, last_utm_campaign, last_utm_content, last_utm_term, last_campaign_key, last_landing_slug, last_touch_at |

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

Explicit GRANT/REVOKE require matching boolean marketing_opt_in and RFC3339 marketing_opt_in_at in fields. Runtime verifies the saved entity map against current live CRM schema without provisioning on requests. Guest submissions to an already linked user contact preserve its product identity, name and domain/list state. Database error printing is suppressed during authenticated processing and restored afterward; errors returned/stored by this plugin are fixed codes only.

## Marketing projection boundaries

JAKAWI retains complete event history; FluentCRM receives only marketing segmentation. No per-view records, activities or tags. No click IDs (fbclid/gclid/ttclid), raw metadata, fingerprint or full query strings. landing_view and landing_cta_click never enqueue CRM delivery.

FIRST selects occurred_at ASC, id ASC and freezes one coherent touch, including nullable content/term. Never fill holes from another touch. LATEST selects occurred_at DESC, id DESC and updates only for a newer touch clock. source-meta/source-tiktok/source-google derive only from frozen FIRST; NONE has no source tag. LATEST provider stays in fields. lead_source/lead_form are FIRST_WRITE; form is a stable surface key, never a URL. signup_at is first-write. last_activity_type and last_activity_at update together only for a strictly newer activity timestamp.

Canonical activity values: lead_submitted, signup_completed, profile_updated, marketing_preference_changed, membership_purchase_requested, membership_activated, benefit_redeemed, experience_reserved, challenge_joined, unlock_committed, partner_application_submitted, program_application_submitted. Membership state updates can project domain status without introducing another activity value.

Register → users / jakawi-user; promotion removes leads. Partner application with email → leads / jakawi-lead / partner-applicant; never confirmed partner. Program → existing user / program-applicant. Membership request → membership-requested; activation removes that tag and member-expired, adds member-active; expiration removes member-active and adds member-expired. Domain membership fields remain authoritative. Benefit/Experience/Challenge/Unlock → benefit-redeemer/experience-reserver/challenge-participant/unlock-participant. Profile → identity/city/lifecycle/preference. A confirmed product partner relation → partners / jakawi-partner.

Consent checkbox: “Quiero recibir novedades, beneficios y experiencias de JAKAWI por email.” Initially checked on new register/partner forms; backend missing/unchecked=false. New true=subscribed, new false=transactional; explicit preference false=unsubscribed; ordinary event=UNCHANGED. No double opt-in. Suppressions unsubscribed/bounced/complained/spammed are always preserved.

## Schema reconciliation hotfix — 1.0.1

Schema V1 remains 3 lists, 15 tags and 32 fields. Provision/repair reads live entities before writes, preflights conflicts, discards stale cached IDs, reuses compatible slug matches (including new IDs), creates missing entities, saves a fresh mapping and validates it before READY. Custom field identity is its slug; the stored field map caches the expected type, not a numeric ID. Display title/label edits do not alter a compatible stable identity.

Missing schema is repairable DRIFT after prior state, or NOT PROVISIONED on an untouched empty schema. A previous failed provisioning option cannot latch CONFLICT indefinitely. Real conflicts are ambiguous live matches, a contract title/label claimed under a different slug, or an incompatible field type. Sanitized diagnostics contain entity_kind (LIST/TAG/FIELD), contract_key, fixed error_code and fixed reason, with no provider text, PII or credentials. Counts reflect live compatible definitions and include expected counts.


## Admin diagnostics and current operation

Tools → Jakawi CRM Bridge displays live schema status **NOT PROVISIONED / READY / DRIFT / CONFLICT / ERROR**, actual compatible counts vs expected 3/15/32 and sanitized entity_kind/contract_key/error_code/reason. Technical event status totals are also shown; never paste contact bodies or signatures into logs/docs.

Missing schema is repairable via the explicit nonce-protected **PROVISION / REPAIR CRM SCHEMA** Admin POST (manage_options). Activation installs technical storage/version only; neither activation nor authenticated delivery provisions lists/tags/fields automatically. Diagnose drift/conflicts before a separately authorized repair; do not provision as a monitoring check.

Laravel production sync is **CRM_SYNC_ENABLED=true**. Meta provider/browser/CAPI effective flags are false; Meta is separate from this bridge. Delivery retries and requeue live in Laravel, not WordPress: six attempts, 1m/5m/15m/1h/6h; `crm:requeue {deliveryId}` preserves UUID/body/source/attempt history and only makes DEAD/RETRY eligible. CRM failure never rolls back product action.

WordPress cron and current automation state are documented once in the [JAKAWI CRM operations runbook](https://github.com/soyjavierquiroz/jakawi-100/blob/main/docs/CRM_FOUNDATION_V1.md). Page-load cron is disabled; server cron calls wp-cron every minute using a lock and bounded HTTP timeout. Automations 1–4 remain DRAFT/INACTIVE; Partner Applicant V1 is PUBLISHED/ACTIVE. Do not publish/enrol automations merely to validate delivery.

Authoritative ownership: this README covers plugin configuration/security/schema/diagnostics; the linked JAKAWI document covers sender outbox, cron and automation operations. [Historical validation/hotfix notes](docs/HISTORY.md) are retained as reference and do not establish current runtime state. [CHANGELOG](CHANGELOG.md) records versions; this documentation update keeps plugin **1.0.1**.
