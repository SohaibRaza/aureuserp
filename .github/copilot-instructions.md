# Repository instructions

Read [AGENTS.md](../AGENTS.md) before making changes. It contains the authoritative project workflow, Git restrictions, testing safeguards and module conventions. These instructions supplement it; they do not override it.

## Find the owning module and current design

- AureusERP is modular. Business code belongs in its owning package under plugins/, following nearby code and existing naming conventions.
- Before Logistics work, read [the Logistics entry point](../docs/logistics/README.md), then select the relevant documents from [the consolidated plan](../docs/logistics/plan/README.md). Business requirements and approved decisions take precedence over existing code and historical reports.
- Before architectural changes, consult [the ADR index](../docs/adr/README.md). Record consequential approved decisions and preserve superseded decisions as history.
- For issue and Project tracking, follow [ADR 0006](../docs/adr/0006-issue-and-project-tracking.md). Use generic repository labels and the owning Project's Phase field.

## Runtime and databases

- Read composer.json and composer.lock for the current PHP and package requirements. Read package.json and its lockfile for frontend tools. Do not use old copied framework-version lists as specifications.
- The current PHP range is 8.4.1 through 8.4.x. PHP 8.3 cannot install the updated Symfony dependencies; the locked spreadsheet library excludes PHP 8.5. CI uses PHP 8.4.
- PostgreSQL is primary; MySQL 8.0 is also a supported target under [ADR 0008](../docs/adr/0008-postgresql-primary-and-mysql-support.md). Keep both CI lanes and report outstanding MySQL portability failures. A PostgreSQL pass does not prove MySQL compatibility. MariaDB is not covered.
- The production Git branch contains development work. No deployment or production environment is configured. Do not require a deployment preflight to continue development, or treat development data as disposable.

## Implementation

- Reuse existing plugin resources, models, services and UI components. Prefer simple, maintainable changes; do not add dependencies without authorization.
- Use explicit PHP parameter and return types, braces for control structures, and constructor property promotion where useful. Match existing enum naming and model-cast conventions.
- Use Eloquent relationships and eager loading for ordinary model access. Use the query builder or DB facade when it makes transactional, locking or set-based operations clearer.
- Validate and authorize on the server, including Company and record scope. Hiding an action is insufficient. Keep user-facing messages in the owning plugin's translations.
- Use config() outside configuration files. Prefer named routes and existing authentication and authorization mechanisms.
- Filament and Livewire behavior must follow the installed versions. Keep authorization, validation, loading feedback, keyboard access, labels and responsive behavior intact. Inspect the existing panel and component conventions before generating files.
- Follow the repository's Tailwind conventions and preserve dark mode where present. Reuse layouts and spacing utilities. If changes are not visible, check the asset build and the served checkout before changing unrelated code.

## Tools and verification

- Prefix shell commands with rtk when installed. Use Laravel Boost when available; otherwise inspect local source and version-matched official documentation. Check command options before using generators and pass --no-interaction to executable Artisan commands.
- Use the repository plugin-builder and test-case-builder skills when their scopes apply, as described in AGENTS.md.
- Tests use Pest, with PHPUnit as the underlying runner. Preserve Pest tests; do not convert them to PHPUnit classes. Use existing factories, states and helpers.
- Read [tests/README.md](../tests/README.md) before database-backed checks. Test bootstrap can rebuild and install the ERP. Use private test databases; never reset application data as routine setup. Existing feature tests use DatabaseTransactions, not RefreshDatabase.
- Start with the smallest meaningful regression checks, review fixes, and broaden verification only when required. Keep PostgreSQL and MySQL results distinct. Separate database sessions are required to establish concurrency behavior.
- Run Pint only on PHP files owned by the task. Do not format the whole repository or unrelated dirty files. Do not create verification scripts when existing tests cover the behavior.
- Do not delete tests without approval. Report failures and unverified behavior honestly; a build or focused pass is not full release acceptance.

## Git and documentation

- Preserve unrelated working files and staged changes. Stage explicit paths and inspect the staged set before committing.
- Follow AGENTS.md's requirement for fresh explicit authorization before commits, pushes and history-changing Git operations. Do not add agent attribution to commit messages.
- Update relevant existing documentation when behavior changes. Create new documentation only when requested or needed to record the user's explicitly approved architectural decision. Keep drafts distinct from approved plans.
- Be concise and direct. State what changed, which checks ran, their results and remaining gaps. Recommend the next useful step.
