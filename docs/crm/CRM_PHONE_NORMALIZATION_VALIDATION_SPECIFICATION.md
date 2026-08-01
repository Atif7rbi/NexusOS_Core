# CRM / Leads v1 — Phone Normalization & Validation Specification

**Project:** NexusOS Pilot
**Module:** CRM / Leads v1
**Version:** 1.0
**Status:** FROZEN
**Repository:** `Atif7rbi/ufq-pilot`

---

## 1. Purpose

This document defines the single mobile-number contract used by CRM / Leads v1 and the existing NexusOS Pilot fields that represent mobile numbers.

It defines the business-visible rules for preprocessing, validation, persistence, matching, duplicate handling, frontend behavior, database enforcement, rollout safety, and testing before CRM DDL or implementation begins.

This specification supplements, and does not replace:

```text
docs/crm/CRM_LEADS_V1_ARCHITECTURE_BUSINESS_RULES.md
```

The CRM / Leads v1 aggregate boundaries, lifecycle, authorization, visibility, conversion, archive, and audit rules remain unchanged.

---

## 2. Scope

This policy governs every current field whose approved meaning is a Saudi mobile number:

```text
customers.phone
leads.phone
users.phone
system_settings.phone
```

It also governs any future field explicitly defined as a mobile number unless a Product Owner amendment approves a different policy.

This specification covers:

- Saudi mobile-number input;
- canonicalization;
- format validation;
- nullable-field behavior;
- persistence and display;
- exact identity matching;
- Customer uniqueness;
- Lead duplicate behavior;
- returning-Customer behavior;
- Lead conversion matching;
- frontend behavior;
- PostgreSQL enforcement;
- existing-data inspection;
- rollout ordering;
- required regression coverage.

---

## 3. Definitions

### 3.1 Mobile Number

In v1, a mobile number is a Saudi mobile number represented by exactly ten ASCII digits beginning with `05`.

### 3.2 Raw Input

The value received from a user, API client, command, import, or internal application caller before preprocessing.

### 3.3 Preprocessed Value

The value after the approved ASCII edge-whitespace characters are removed and Arabic-Indic or Persian digits are converted to ASCII digits.

### 3.4 Canonical Value

The preprocessed value after it has successfully matched the canonical validation pattern. Because v1 does not accept country-code formats or decorative separators, the valid preprocessed value is also the stored canonical value.

### 3.5 Exact Identity Match

Equality between two canonical mobile values:

```text
left_phone = right_phone
```

Exact identity matching is not substring matching, fuzzy matching, or comparison after removing arbitrary punctuation.

### 3.6 Governed Field

Any field listed in Section 2 or explicitly designated in a future approved specification as a mobile-number field.

---

## 4. Canonical Mobile Format

The only accepted canonical format is:

```text
05XXXXXXXX
```

The canonical validation pattern is:

```regex
^05[0-9]{8}$
```

The canonical value:

- contains exactly ten characters;
- contains ASCII digits only;
- begins with `05`;
- contains exactly eight ASCII digits after `05`;
- contains no country code;
- contains no formatting symbols;
- contains no extension.

Accepted examples:

```text
0501234567
0531234567
0559876543
```

Rejected examples:

```text
1234567890
051234567
05012345678
+966501234567
966501234567
00966501234567
050 123 4567
050-123-4567
(050)1234567
abc0501234567
```

No country-code transformation is performed in v1. Values beginning with `+966`, `966`, or `00966` are rejected rather than converted.

---

## 5. Input Preprocessing

Preprocessing occurs once and in this order:

```text
1. Preserve null for a nullable governed field.
2. Remove approved ASCII edge whitespace from the beginning and end only.
3. Convert Arabic-Indic digits to ASCII digits.
4. Convert Persian digits to ASCII digits.
5. Validate the complete resulting value.
```

The only removable edge-whitespace characters in v1 are:

| Code point | Name |
|---|---|
| `U+0009` | CHARACTER TABULATION |
| `U+000A` | LINE FEED |
| `U+000B` | LINE TABULATION |
| `U+000C` | FORM FEED |
| `U+000D` | CARRIAGE RETURN |
| `U+0020` | SPACE |

The backend and frontend must implement this exact code-point set. Generic runtime `trim` functions may be used only if they are constrained or wrapped to produce exactly this behavior.

No other Unicode whitespace is removed. In particular:

```text
U+00A0 NO-BREAK SPACE
→ not removed
→ fails mobile format validation
```

Arabic-Indic mapping:

```text
٠١٢٣٤٥٦٧٨٩
0123456789
```

Persian mapping:

```text
۰۱۲۳۴۵۶۷۸۹
0123456789
```

Examples:

```text
" 0501234567 "
→ "0501234567"
→ accepted
```

```text
"٠٥٠١٢٣٤٥٦٧"
→ "0501234567"
→ accepted
```

```text
"۰۵۰۱۲۳۴۵۶۷"
→ "0501234567"
→ accepted
```

The preprocessor must not remove, repair, or reinterpret:

- internal whitespace;
- hyphens;
- parentheses;
- slashes;
- plus signs;
- letters;
- extensions;
- other symbols.

Unsupported Unicode whitespace is treated as an unsupported symbol and is not removed.

Consequently:

```text
"050 123 4567"
→ remains "050 123 4567"
→ rejected
```

For nullable governed fields, an omitted value or `null` remains `null`. An empty value after removing only the approved ASCII edge-whitespace characters is treated as absent: it becomes `null` for a nullable field and fails required validation for a required field.

Preprocessing must be deterministic and idempotent:

```text
preprocess(preprocess(value)) = preprocess(value)
```

---

## 6. Validation Rules

After preprocessing, every non-null governed value must match:

```regex
^05[0-9]{8}$
```

Validation must reject:

- any non-string structured input;
- any non-null value that does not become exactly ten ASCII digits;
- a value not beginning with `05`;
- nine or fewer digits;
- eleven or more digits;
- internal whitespace;
- punctuation;
- a plus sign;
- a country code;
- letters or other symbols.

Validation and authorization are independent. Passing the mobile-number rule never grants access to a Customer, Lead, User, Tenant, or command.

Frontend validation may provide immediate feedback, but backend command validation and PostgreSQL constraints remain authoritative.

---

## 7. Storage Contract

CRM / Leads v1 does not introduce a `phone_normalized` column.

The stored value itself is canonical:

```text
phone = 05XXXXXXXX
```

The same canonical value is used for:

- persistence;
- API representation;
- frontend display;
- exact matching;
- duplicate detection;
- Customer lookup;
- Lead-to-Customer matching;
- conversion matching;
- linking.

The system must not persist the original preprocessed raw representation separately in v1.

Every successful write must store the value returned by the shared mobile-number component, not the unprocessed request value.

No model, controller, Form Request, Action, job, CLI command, import path, conversion flow, or direct application call may intentionally write a non-canonical governed value.

---

## 8. Field-Specific Policies

### 8.1 `customers.phone`

```text
required
canonical Saudi mobile format
unique within Tenant
archived Customers remain inside uniqueness scope
```

The current business identity remains:

```text
tenant_id + phone
```

The same canonical phone may exist in different Tenants.

### 8.2 `leads.phone`

```text
required
canonical Saudi mobile format
not unique
```

Multiple Leads may use the same phone because each Lead is an independent sales opportunity.

### 8.3 `users.phone`

```text
nullable
canonical Saudi mobile format when present
not unique
```

Email remains the login and account identity. User phone does not become a login identifier and does not become unique.

### 8.4 `system_settings.phone`

```text
nullable
canonical Saudi mobile format when present
not unique
```

### 8.5 Future Mobile Fields

Any future field defined as a mobile number must reuse this policy. A landline, switchboard, international contact number, or extension must use a separately approved field and contract rather than weakening this mobile-number policy.

---

## 9. Search and Matching

Phone is the primary operational Customer lookup key.

Exact full-phone matching is mandatory for:

- Customer identity lookup;
- Customer duplicate detection;
- Lead-to-Customer matching;
- conversion;
- linking;
- returning-Customer resolution.

Exact identity lookup preprocesses the supplied input as follows:

```text
remove approved ASCII edge whitespace only
→ convert Arabic-Indic and Persian digits
→ validate the full canonical format
→ compare exact canonical values
```

The comparison is Tenant-scoped:

```text
customers.tenant_id = current_tenant_id
AND customers.phone = canonical_phone
```

Generic list/search interfaces may support partial phone search for discovery. Partial matching must never be used to confirm identity, detect duplicates, select a conversion target, link a Lead, or decide whether a Customer already exists.

A partial-search result does not prove identity.

### 9.1 Hidden Exact Customer Match

An exact Tenant-scoped Customer phone match may exist while the current actor is not permitted to view or link that Customer.

In that case, the system must:

- preserve Customer and Lead visibility rules from the frozen CRM architecture;
- disclose no Customer identity, field, status, or other data;
- omit the hidden Customer from duplicate-warning details;
- reject any attempted explicit link to the hidden Customer;
- allow Lead creation only when the actor otherwise has permission to create the Lead;
- avoid creating a duplicate Customer during later conversion merely because the actor could not view the existing match;
- preserve the approved `404` resource-hiding and `403` role-authorization semantics;
- require an Administrator or another actor explicitly authorized to review and complete the conversion when necessary.

Lead creation does not broaden Customer visibility. A hidden match is not proof to the current actor that a particular Customer exists, and no response may be shaped to reveal that hidden record.

---

## 10. Customer Uniqueness

Customer phone uniqueness remains:

```text
UNIQUE (tenant_id, phone)
```

The uniqueness scope:

- includes active Customers;
- includes inactive Customers;
- includes legacy Customer records;
- includes archived Customers;
- does not cross Tenant boundaries.

Application validation must provide a clear validation response before persistence when possible. PostgreSQL remains the final concurrency-safe enforcement layer.

If another Customer inside the same Tenant already owns the canonical phone, the write is rejected. The system must not:

- merge Customers automatically;
- overwrite the existing Customer;
- transfer historical Leads;
- select one duplicate arbitrarily;
- relax uniqueness for archived Customers.

---

## 11. Lead Duplicate Policy

Lead phone is intentionally not unique.

The same canonical phone may be used by:

- multiple Leads in the same Tenant;
- Leads created at different times;
- independent opportunities for the same person;
- a Lead and an existing Customer.

Lead duplicate warning and visibility behavior remain governed by the frozen CRM / Leads architecture. Duplicate detection uses exact canonical phone equality and never widens the actor's approved visibility scope.

Duplicate detection is advisory for Lead creation. It does not prevent creation of a separate Lead after the required acknowledgement.

---

## 12. Returning Customer Behavior

Customer represents the person and permanent operational record. Lead represents one independent sales opportunity.

A Customer may have multiple Leads over time.

When a returning Customer creates a new opportunity:

1. preprocess and validate the Lead phone;
2. perform a Tenant-scoped exact Customer lookup;
3. show a visible existing Customer match for awareness only;
4. allow creation of a new independent Lead;
5. keep `Lead.customer_id = null`;
6. preserve the complete new Lead lifecycle;
7. do not create a duplicate Customer during later conversion.

A visible match acknowledgement during Lead creation is informational UI state only. It is not persisted as `customer_id`, is not Customer linking, and is not conversion.

The frozen lifecycle invariant is authoritative:

```text
stage != won
→ customer_id IS NULL
```

Customer linking occurs only inside a successful Lead conversion. On conversion, the command rechecks the exact Tenant-scoped Customer match, links the confirmed existing Customer, and sets the Lead to `won` atomically.

A hidden Customer match follows Section 9.1 and is not disclosed through Lead creation.

Each Lead retains an independent:

- source;
- assignment;
- project and Unit interest;
- follow-up;
- lifecycle;
- outcome;
- historical sales context.

The existence of a Customer never means that a new opportunity must reuse an old Lead.

---

## 13. Lead Conversion

Conversion runs through the approved transactional conversion command and preserves all frozen CRM aggregate, lifecycle, authorization, visibility, locking, and audit rules.

### 13.1 No Matching Customer

When no exact Tenant-scoped Customer match exists:

1. create a new Customer;
2. copy only approved Lead conversion data, including name, canonical phone, email when available, and other fields approved by the conversion contract;
3. force `Customer.status = customer` server-side;
4. link `Lead.customer_id`;
5. move the Lead to `won` through the approved conversion command;
6. set `conversion_mode = created`.

### 13.2 Existing Matching Customer

When one exact matching Customer exists, conversion preserves the Customer-state cases frozen in the CRM architecture.

#### 13.2.1 Matching Archived Customer

- conversion is blocked;
- the Customer is not linked;
- the Customer is not restored implicitly;
- an Administrator must restore the Customer through the independent Customer workflow;
- conversion may be retried only after restoration.

#### 13.2.2 Matching Legacy Customer in `lead`

Inside the same atomic conversion transaction, the command must:

1. lock the Lead and matching Customer;
2. validate Tenant, visibility, authorization, Customer archive state, Lead stage, and Lead archive state;
3. promote `Customer.status` from `lead` to `customer`;
4. link `Lead.customer_id`;
5. set `Lead.stage = won`;
6. set `converted_at`, `converted_by`, and the other approved conversion metadata;
7. set `conversion_mode = linked_and_promoted`;
8. clear `next_follow_up_at`;
9. create the approved `stage_change` Activity;
10. commit atomically.

The command must not create a duplicate Customer, promote the Customer outside the conversion transaction, or overwrite Customer identity or contact fields automatically.

#### 13.2.3 Matching Customer in `customer`

- present the Customer for explicit linking confirmation;
- show material data differences for review;
- link the existing Customer without automatically overwriting Customer data;
- set `conversion_mode = linked`.

#### 13.2.4 Matching Customer in `inactive`

The frozen CRM architecture behavior remains unchanged:

- require explicit confirmation;
- link without automatically changing Customer status or data;
- set `conversion_mode = linked`.

For every successful existing-Customer path, linking `Lead.customer_id` and changing `Lead.stage` to `won` occur in the same transaction. Before conversion, `customer_id` remains `null`. There is no already-linked open or lost Lead conversion path in v1. The only approved conversion modes remain `created`, `linked`, and `linked_and_promoted`.

### 13.3 Ambiguous or Changed Match

If more than one matching Customer exists because historical data violated the canonical identity rule:

```text
conversion is blocked
→ integrity conflict
→ administrative data resolution is required
```

If the Customer match changes concurrently after the user reviewed the conversion decision:

```text
HTTP 409 Conflict
→ refresh current Customer match
→ review differences
→ require a new explicit confirmation
```

The command must not silently change a user decision from Customer creation to Customer linking or from one Customer target to another.

When an exact Customer match is hidden from the current actor, conversion follows Section 9.1. Lack of visibility must never cause the command to create a duplicate Customer. The command must stop behind the approved visibility and authorization boundary and require an Administrator or otherwise authorized conversion path where necessary.

The Lead remains preserved as a historical sales record after conversion.

---

## 14. Customer Updates and Historical Leads

Authorized users may update current Customer data, including phone, subject to the existing Customer authorization and archive rules.

When `Customer.phone` changes:

- preprocess and validate the new value;
- require the canonical `05XXXXXXXX` format;
- preserve Tenant-scoped uniqueness;
- reject `422` if another Customer in the Tenant owns the value;
- do not merge Customers automatically;
- do not rewrite historical Leads.

Customer represents current official information. Historical Leads retain the name, phone, and sales context recorded for that opportunity.

Future Leads use the Customer's current data at the time those Leads are created. A later Customer update does not retroactively alter earlier Lead snapshots or Activities.

### 14.1 Changing Phone on an Open Lead

When an open Lead phone is changed, the update flow must:

1. preprocess and validate the proposed phone;
2. rerun Lead duplicate discovery;
3. rerun exact Tenant-scoped Customer-match discovery;
4. present only matches visible under the frozen visibility rules;
5. persist the canonical Lead phone while keeping `customer_id = null`.

The system must not:

- persist an early Customer link;
- persist match acknowledgement as `customer_id`;
- modify `Customer.phone` automatically;
- overwrite other Customer data automatically;
- rewrite any other Lead.

All open Lead stages satisfy `customer_id IS NULL`. Closed, lost, and `won` Lead edit restrictions remain governed by the frozen Lead lifecycle and are not relaxed by this phone policy.

---

## 15. Error Contract

The following failure categories are distinct:

| Failure | HTTP status | Required meaning |
|---|---:|---|
| Invalid mobile format | `422` | The governed value is absent when required or fails the canonical format after preprocessing. |
| Duplicate Customer phone | `422` | Another Customer in the same Tenant owns the canonical phone. |
| Customer match changed during conversion | `409` | A previously reviewed phone-match decision or conversion target is stale and must be reviewed again. |
| Hidden or inaccessible Customer or Lead | `404` | The resource is outside Tenant or role visibility scope. |
| Unauthorized role | `403` | The authenticated active member lacks authority for the requested operation. |

The frontend must distinguish invalid format from Customer uniqueness failure and display the approved message for each.

The exact JSON error envelope and final symbolic `error.code` strings are frozen later in the CRM API specification. That technical detail must preserve the distinctions and HTTP statuses above.

Database unique or CHECK violations must be translated to the same public semantic failures; raw PostgreSQL errors must not be exposed.

---

## 16. Frontend Contract

Every governed phone input displays:

```text
05XXXXXXXX
```

as its placeholder.

No permanent helper text is displayed below the field.

The input behavior must:

- accept ASCII digits;
- convert Arabic-Indic digits to ASCII while entering or pasting;
- convert Persian digits to ASCII while entering or pasting;
- prevent input beyond ten digits;
- reject rather than silently repair formatting symbols;
- avoid converting country-code formats;
- use mobile-friendly telephone or numeric input behavior;
- preserve accessible labels and error association;
- support RTL and LTR layouts.

The frontend must not silently truncate an eleven-digit pasted value into a valid ten-digit value. It must prevent the invalid edit or retain an invalid state that produces validation feedback.

Invalid-format message:

```text
يجب أن يكون رقم الجوال مكونًا من 10 أرقام ويبدأ بـ 05.
```

Duplicate-Customer message:

```text
رقم الجوال مستخدم لعميل آخر.
```

Frontend checks improve user experience only. Every server command reprocesses and validates its own input, and PostgreSQL remains authoritative.

---

## 17. Shared Component Responsibilities

Future implementation must provide one deterministic shared mobile-number component in a repository-consistent shared boundary such as:

```text
backend/app/Modules/Shared/
```

The shared component is reused by:

- Customer create and update;
- Lead create and update;
- Lead conversion;
- exact Customer lookup;
- duplicate detection;
- User create and update;
- System Settings update;
- future governed mobile fields;
- internal Actions, commands, imports, and jobs that write or match governed values.

Its responsibilities are:

```text
preprocess nullable or required input
→ remove only U+0009, U+000A, U+000B, U+000C, U+000D, and U+0020 from edges
→ convert Arabic-Indic and Persian digits
→ validate the complete canonical pattern
→ return canonical value or a typed validation failure
```

The component must be:

- deterministic;
- idempotent;
- independent of HTTP;
- safe for direct Action invocation;
- reusable without duplicating regex or digit maps;
- free of Tenant lookup and authorization responsibilities.

Form Requests may call a shared validation rule or prepare input for presentation, but normalization must not exist only in Form Requests. Controllers must not contain duplicate mobile-format logic. A model mutator must not become the only enforcement point because lookup, validation, conversion, and pre-persistence conflict handling also require the same contract.

Persistence remains the responsibility of the owning module's Action. Tenant-scoped lookup and uniqueness remain responsibilities of Customer and Lead application/domain flows. PostgreSQL remains the final invariant layer.

---

## 18. PostgreSQL Constraints

PostgreSQL remains the final line of defense.

The semantic constraints are:

### 18.1 Customers

```sql
phone IS NOT NULL
AND phone ~ '^05[0-9]{8}$'
```

Existing uniqueness remains:

```text
UNIQUE (tenant_id, phone)
```

Archived Customers remain inside the unique scope.

### 18.2 Leads

```sql
phone IS NOT NULL
AND phone ~ '^05[0-9]{8}$'
```

Lead phone is not unique. It requires an index appropriate for Tenant-scoped exact lookup:

```text
tenant_id + phone
```

The Leads CHECK constraint and Tenant-scoped phone index are created with the `leads` table as part of CRM DDL. They are not installed during the existing-fields rollout before that table exists.

### 18.3 Users

```sql
phone IS NULL
OR phone ~ '^05[0-9]{8}$'
```

User phone remains non-unique.

### 18.4 System Settings

```sql
phone IS NULL
OR phone ~ '^05[0-9]{8}$'
```

System Settings phone remains non-unique.

The future migrations must:

- use explicit named CHECK constraints;
- preserve the existing Customer uniqueness scope;
- add no `phone_normalized` column;
- add no automatic country-code conversion;
- refuse to enable a constraint while invalid governed data remains;
- avoid silently deleting, merging, or rewriting business records;
- remain safe under real PostgreSQL concurrency.

Laravel validation and `Rule::unique` are not substitutes for database constraints.

---

## 19. Existing Data Inspection

The current operational environment is reported to contain only two experimental Customers with different raw phone values. This must still be verified against the actual deployment before constraints are applied.

Users do not require phone because email remains the login identity. User and System Settings phone values may be `null`.

Before enabling final constraints:

1. inspect every non-null governed phone value;
2. inspect the two Customer phone values;
3. derive the proposed canonical value using only approved ASCII edge-whitespace removal and digit conversion;
4. classify each value as invalid, transformable, or already stored canonically;
5. report every invalid value;
6. detect Customer collisions using the proposed canonical values;
7. stop if invalid values or collisions remain;
8. obtain explicit Product Owner approval for every data correction;
9. write approved canonical values for transformable records;
10. rerun collision detection and raw-storage validation;
11. confirm every non-null stored value itself matches `^05[0-9]{8}$`;
12. install CHECK constraints only after all preceding checks pass.

The inspection must distinguish:

```text
logically valid after preprocessing
!=
already stored in canonical ASCII form
```

Values requiring an explicit approved canonical data write include:

- Arabic-Indic digits;
- Persian digits;
- approved ASCII edge whitespace.

Values containing internal spaces, punctuation, country-code formats, unsupported Unicode whitespace, symbols, or other ambiguous content are invalid and must not be silently repaired.

If an experimental Customer phone is invalid, it may be corrected manually or the experimental Customer may be deleted only after explicit Product Owner approval. Transformable values also require approved explicit writes; successful preprocessing during inspection does not itself change stored data.

No full database reset is permitted. No complex historical Customer backfill is required for the currently reported environment.

If inspection discovers unexpected invalid data, duplicate canonical Customers, or non-experimental affected records:

```text
stop rollout
→ report exact records and conflict class
→ obtain Product Owner data-resolution approval
→ resolve data explicitly
→ rerun inspection
```

The migration must fail safely before installing final constraints if preconditions are not satisfied. It must not select a Customer, merge records, or discard data automatically.

After every approved canonical rewrite, Customer collisions and raw canonical-storage compliance must be checked again before any constraint is installed. No Customer is selected arbitrarily when a collision exists, and no automatic Customer merge is permitted.

Inspection queries must run read-only before any approved correction or migration.

---

## 20. Safe Rollout Sequence

The rollout is split into two ordered phases.

### 20.1 Phase A — Existing Governed Fields

Phase A applies only to:

```text
customers.phone
users.phone
system_settings.phone
```

Its order is mandatory:

```text
1. Prepare and validate the shared normalization and validation component.
2. Update existing Customer, User, and System Settings application write paths to use the shared component.
3. Update current Customer exact lookup and duplicate detection.
4. Establish an approved cutover barrier before final data inspection.
5. Inspect raw Customer, User, and System Settings phone values and derive proposed canonical values without writing data.
6. Report invalid values and transformable non-canonical values.
7. Detect Customer collisions after canonicalization.
8. Stop if invalid values or collisions remain and obtain explicit Product Owner approval for data corrections.
9. Write approved canonical values for transformable records.
10. Rerun collision detection and raw-storage validation while the cutover barrier remains active.
11. Confirm every non-null stored value itself matches ^05[0-9]{8}$.
12. Add PostgreSQL CHECK constraints for existing governed columns while preserving Customer uniqueness.
13. Verify that every required constraint is active.
14. Remove the cutover barrier only after successful constraint verification.
15. Update existing Customer, User, and System Settings frontend fields.
16. Run existing-module backend, database, concurrency, and frontend tests.
17. Verify Customers, Users, and System Settings end to end.
```

From the start of the final canonical inspection until all PostgreSQL phone constraints are installed and verified, every governed write must either be blocked or already pass through the new canonical shared component. There must be no interval in which an unrestricted legacy write path can insert a non-canonical governed value after final inspection and before constraint installation.

The approved cutover barrier may use maintenance mode for affected writes, temporary write blocking, deployment ordering that guarantees only canonical writes, or another explicitly reviewed locking or guard mechanism. This specification freezes the safety invariant and ordering, not the final production mechanism.

If constraint installation fails, the deployment must keep or restore the approved write barrier, must not resume unsafe writes, must report the failure, and must resolve the data or migration issue explicitly before rerunning inspection and verification. Unsafe writes remain blocked until all required constraints are confirmed active.

### 20.2 Phase B — CRM Leads

Phase B begins only after Phase A and the required CRM DDL approvals:

```text
1. Create leads.phone with its CHECK constraint as part of CRM DDL.
2. Create the Tenant-scoped Lead phone index as part of CRM DDL.
3. Implement Lead writes using the approved shared mobile-number component.
4. Implement Lead matching and conversion using that component while preserving customer_id = null before won.
5. Run CRM-specific domain, API, database, concurrency, and frontend tests.
6. Enable CRM behavior only after all CRM acceptance gates pass.
```

The Leads CHECK constraint and index must not be installed or described as installed before the `leads` table exists.

No production Lead conversion behavior may be enabled before canonical Customer matching, existing-data inspection, conflict resolution, application writes, and PostgreSQL enforcement are ready together.

Deployment must not create a window in which application writes can introduce values rejected by imminent constraints or in which conversion uses a different identity rule from Customer writes.

---

## 21. Required Tests

### 21.1 Shared Component

Accepted:

- valid ASCII `05XXXXXXXX`;
- Arabic-Indic digits converted to ASCII;
- Persian digits converted to ASCII;
- each approved ASCII edge-whitespace character is removed from the beginning and end;
- combinations of `U+0009`, `U+000A`, `U+000B`, `U+000C`, `U+000D`, and `U+0020` behave identically in backend and frontend;
- repeated preprocessing is idempotent;
- `null` accepted for nullable fields;
- empty input becomes `null` for nullable fields.

Rejected:

- missing required value;
- empty required value;
- wrong prefix;
- nine digits;
- eleven digits;
- arbitrary ten-digit value not beginning with `05`;
- internal spaces;
- `U+00A0 NO-BREAK SPACE` at either edge or internally;
- unsupported Unicode whitespace;
- hyphens;
- parentheses;
- slash;
- plus sign;
- `+966`, `966`, and `00966` formats;
- letters;
- symbols;
- arrays, objects, and other non-string inputs.

### 21.2 Customers

- create stores the canonical phone;
- update stores the canonical phone;
- direct Action invocation applies the shared component;
- duplicate canonical phone inside the same Tenant returns `422`;
- the same canonical phone across different Tenants succeeds;
- archived Customer blocks duplicate creation and update;
- PostgreSQL rejects invalid direct persistence;
- PostgreSQL uniqueness protects concurrent writes;
- Customer search and exact lookup use their approved distinct semantics;
- API response and frontend display use the canonical value.

### 21.3 Leads and Conversion

- duplicate Lead phones are allowed;
- exact Customer matching is Tenant-scoped;
- hidden Customer information is not disclosed;
- returning Customer creates a new independent Lead;
- returning Customer Lead is created with `customer_id = null`;
- a visible Customer match may be shown for awareness but does not persist an early link;
- match acknowledgement is not persisted as `customer_id`;
- no already-linked open or lost Lead conversion path exists;
- Lead creation remains allowed when frozen permissions allow it;
- a hidden Customer match is not disclosed through warnings or responses;
- hidden-match Lead creation does not authorize duplicate Customer creation during conversion;
- conversion links one matching Customer;
- conversion links `customer_id` and sets `stage = won` atomically;
- conversion rechecks the exact Customer match before linking;
- conversion creates a Customer when no match exists;
- created Customer status is `customer`;
- archived matching Customer blocks conversion;
- blocked conversion does not restore an archived Customer implicitly;
- conversion may be retried after an Administrator restores the matching Customer through the independent Customer workflow;
- matching legacy Customer in `lead` is promoted to `customer` atomically with Lead conversion;
- legacy Customer promotion uses `conversion_mode = linked_and_promoted`;
- matching Customer in `customer` uses `conversion_mode = linked`;
- linking does not overwrite Customer data;
- ambiguous historical Customer matches block conversion;
- concurrent Customer match change returns `409`;
- changing an open Lead phone reruns duplicate and Customer-match discovery;
- changing an open Lead phone keeps `customer_id = null`;
- open Lead phone update never modifies Customer phone or another Lead;
- conversion rollback leaves the Customer and Lead unchanged and creates no partial Customer, Lead mutation, or Activity;
- Lead phone snapshots remain canonical.

### 21.4 Customer History

- Customer phone update does not rewrite historical Leads;
- future Leads use the Customer's current phone at creation time;
- old Lead Activities and conversion records remain unchanged.

### 21.5 Existing Data and Constraint Rollout

- inspection distinguishes transformable values from already-canonical stored values;
- Arabic-Indic stored values are rewritten to canonical ASCII before CHECK installation;
- Persian stored values are rewritten to canonical ASCII before CHECK installation;
- approved ASCII edge-whitespace values are rewritten before CHECK installation;
- `U+00A0` values are reported invalid rather than rewritten;
- collision detection runs against proposed canonical Customer values before writes;
- collision detection reruns after approved canonical writes;
- constraints are not installed while invalid values or collisions remain;
- every non-null stored value matches `^05[0-9]{8}$` before constraint installation;
- no automatic Customer merge or arbitrary Customer selection occurs.
- legacy write paths cannot write non-canonical governed values during cutover;
- a concurrent non-canonical write cannot enter after final inspection;
- required PostgreSQL constraints are active before the cutover barrier is removed;
- failed constraint installation keeps or restores the barrier and does not reopen unsafe writes.

### 21.6 Users and System Settings

- `null` remains accepted;
- a valid canonical value succeeds;
- Arabic-Indic and Persian digits are stored as ASCII;
- invalid non-null values return `422`;
- PostgreSQL rejects invalid direct persistence;
- phone remains non-unique;
- email remains User login identity.

### 21.7 Frontend

- placeholder is `05XXXXXXXX`;
- no permanent helper text appears;
- ASCII digits are accepted;
- Arabic-Indic digits convert correctly;
- Persian digits convert correctly;
- approved ASCII edge whitespace matches backend behavior exactly;
- `U+00A0` is rejected consistently with backend behavior;
- more than ten digits are prevented without silent valid truncation;
- formatting symbols are not silently repaired;
- invalid-format message is displayed;
- duplicate-Customer message is displayed;
- nullable User and System Settings fields can be cleared;
- backend errors remain authoritative;
- behavior is accessible in RTL and LTR layouts.

All database, uniqueness, conversion-race, and direct-write enforcement tests must run on PostgreSQL.

---

## 22. Out of Scope

CRM / Leads v1 does not include:

- landline numbers;
- international mobile formats;
- automatic `+966` conversion;
- automatic `00966` conversion;
- country selection;
- country-code storage;
- phone extensions;
- switchboard numbers;
- WhatsApp-specific identity;
- multiple phone numbers per Customer, Lead, User, or System Settings record;
- phone verification by SMS or OTP;
- carrier lookup;
- number portability checks;
- contact merging;
- automatic Customer merging;
- a `phone_normalized` column;
- preservation of the original decorated phone input;
- use of phone as a User login identifier;
- rewriting historical Lead phone snapshots after Customer updates.

Any such capability requires an explicit Product Owner amendment and its own technical and API contracts.

---

## 23. Open Technical Design Items

No approved business decision is open.

Only the following implementation-shape details remain for the technical, DDL, API, and frontend specifications:

1. Exact PHP class names for the shared preprocessor, value, validator, exception, and Laravel Rule integration.
2. Exact namespace and subdirectory under `backend/app/Modules/Shared/`.
3. Whether one class or a small cohesive set of classes represents preprocessing and validated canonical output.
4. Exact PostgreSQL CHECK constraint names.
5. Exact Lead phone lookup index name.
6. Exact migration filenames and safe sequencing.
7. Exact typed exception classes and final API `error.code` strings while preserving Section 15 semantics and statuses.
8. Exact frontend utility, hook, and component file names.
9. Exact mechanics for preventing or displaying invalid eleventh-digit and symbol input without silent repair.
10. Exact lock and retry implementation used to translate concurrent Customer match changes to the approved `409` behavior.
11. Exact reviewed cutover-barrier mechanism used to prevent legacy or non-canonical governed writes between final inspection and successful constraint verification.

These items may not reopen:

- Saudi-mobile-only scope;
- canonical `05XXXXXXXX` storage;
- approved digit conversion;
- rejection of formatting and country-code inputs;
- field nullability or uniqueness;
- exact matching requirements;
- returning-Customer behavior;
- Lead conversion rules;
- historical Lead preservation;
- PostgreSQL enforcement;
- rollout ordering.

---

## 24. Freeze Statement

CRM / Leads v1 — Phone Normalization & Validation Specification v1.0 is FROZEN and is the approved reference for implementation.

It is the authoritative phone normalization and validation contract for CRM / Leads v1 and every governed field defined in this document.

```text
FROZEN
```

After this freeze, any change to supported phone type, canonical format, preprocessing, validation, storage, field scope, uniqueness, matching, conversion, frontend behavior, database enforcement, rollout order, or required test coverage requires an explicit Product Owner amendment.
