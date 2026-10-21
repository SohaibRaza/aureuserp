# ADR 0007: PostgreSQL is the AureusERP project database baseline

- **Status:** Superseded by [ADR 0008](0008-postgresql-primary-and-mysql-support.md) on 2026-10-21
- **Date:** 2026-09-24
- **Decision owner:** AureusERP project owner

## Context

The repository can be configured with several database engines, and inherited setup documents and Logistics plan text mention MySQL, MariaDB, and PostgreSQL. Those statements describe framework or upstream possibilities; they do not establish which engine this project deploys or must pass for acceptance.

During the Logistics P0 work, a request to reproduce tests on MySQL 8 was treated as evidence that MySQL was a supported project target. That led to adding MySQL-specific test-runner setup, investigating migration portability, and creating MySQL migration issues even though the project uses PostgreSQL. This was unnecessary work and incorrectly presented an unsupported engine as a P0 gate.

## Decision

1. PostgreSQL is the database engine selected for this project and the authoritative database for development, test, and release acceptance.
2. Run the standard isolated test baseline and database concurrency tests on PostgreSQL. Record the exact PostgreSQL version and target identity in test evidence.
3. Do not infer project support for another engine from Laravel capabilities, generic installation requirements, `.env.example`, historical plans, or an isolated compatibility test.
4. A request to inspect another engine may be handled as a bounded exploratory compatibility check when safe. The result must be identified as exploratory; it must not change the standard runner, supported-platform claims, plan acceptance criteria, issue backlog, or release gates.
5. Add another supported engine only through a new accepted ADR that defines its scope, minimum version, required migration and schema behavior, test infrastructure, operational support, and release acceptance. Until then, cross-engine requirements in older documents are not acceptance gates for this project.

## Consequences

- PostgreSQL results determine whether the project's database-dependent work passes acceptance.
- MySQL and MariaDB portability work is out of scope unless the support decision changes through an accepted ADR.
- Generic upstream documentation may continue to describe other Laravel database adapters, but project-specific guidance must identify PostgreSQL as the tested and supported target.
- Existing Logistics plans may contain older cross-engine test language. This ADR governs the platform decision; update those plan documents when they are next authorized for editing. Do not silently expand current implementation work to satisfy the older wording.

## References

- [Repository agent instructions](../../AGENTS.md)
- [Logistics testing and acceptance plan](../logistics/plan/09-TESTING-AND-ACCEPTANCE.md)
- [P0 execution report](../logistics/P0-EXECUTION-REPORT-2026-09-24.md)
