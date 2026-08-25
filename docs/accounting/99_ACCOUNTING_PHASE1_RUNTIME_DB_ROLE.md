# Accounting Phase 1 — PostgreSQL Runtime Role

Status: **FROZEN IMPLEMENTATION PREREQUISITE**

Accounting migrations must run through a PostgreSQL migration owner and the
application must run through a distinct, unprivileged role. The existing
single `DB_USERNAME` configuration does not by itself prove this separation.
Phase 1 therefore fails closed unless `ACCOUNTING_RUNTIME_DB_ROLE` names a
pre-provisioned role that:

- exists before the Accounting migrations run;
- differs from the connected migration role;
- is not superuser and has no `CREATEDB`, `CREATEROLE`, `REPLICATION`, or
  `BYPASSRLS` capability;
- does not own the `public` schema, Accounting tables, or Accounting trigger
  functions.

Infrastructure creates the role; application migrations do not create or
alter cluster roles. A representative administrator-owned command is:

```sql
CREATE ROLE nexusos_runtime LOGIN PASSWORD '<managed secret>'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
```

The release environment then sets:

```text
DB_USERNAME=<migration owner>
ACCOUNTING_RUNTIME_DB_ROLE=nexusos_runtime
```

The final Phase 1 migration revokes all Accounting table privileges from the
runtime role and grants only the required DML surface. In particular:

- `accounting_source_types`: `SELECT` only;
- `accounting_settings`: `SELECT`, `INSERT` only;
- Accounts and Periods: no `DELETE` or `TRUNCATE`;
- Journals, Lines, and Opening Operations: Draft lifecycle DML only, with
  database triggers remaining authoritative;
- Accounting audits: `SELECT`, `INSERT` only;
- trigger-function `EXECUTE` is revoked from both `PUBLIC` and the runtime
  role.

Because the runtime role is neither object owner nor superuser it cannot
disable triggers, replace trigger functions, or grant itself missing rights.
Before applying grants, the migration queries PostgreSQL catalogs and aborts
if the configured runtime role owns the `public` schema, any protected
Accounting/number-sequence table, or any Accounting trigger function.
CI provisions this split-role model and tests source-catalog insertion,
`TRUNCATE`, trigger disabling, and function replacement as the runtime role.

Deployment and production role provisioning remain outside Phase 1. A release
must stop before migration if the prerequisite role is absent or privileged.
