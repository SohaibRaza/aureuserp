# Agent Guidelines

## Project overview and navigation

AureusERP is a modular Laravel ERP with Filament panels and Livewire components. Business modules live in Composer packages under `plugins/`; inspect the owning plugin before placing domain code in the application shell.

| Path | Purpose |
| --- | --- |
| `app/`, `bootstrap/`, `routes/` | Application services, panel integration, bootstrapping, and routes |
| `plugins/webkul/` | ERP business modules and shared plugin infrastructure |
| `plugins/shafique/logistics/` | Logistics module |
| `tests/`, `plugins/*/*/tests/` | Application and plugin tests; suite selection is in `phpunit.xml` |
| `docs/adr/`, `docs/logistics/` | Architecture decisions and Logistics design |
| `.agents/skills/` | Repository-specific implementation workflows |

Read any more specific agent instructions governing the files being changed. Keep work within the requested scope and preserve unrelated edits.

## Project guidance

- Check `composer.json`/`composer.lock` and `package.json`/the package lockfile for current framework and tool versions; do not rely on copied version numbers here.
- Follow existing code and naming conventions. Inspect nearby implementations and reuse project components where appropriate.
- Follow SOLID, DRY, YAGNI, KISS, and other principles.
- Optimize for clarity, maintainability, and correctness. Choose the simpler approach unless added complexity provides a concrete present-day benefit. Avoid over-engineering, premature abstractions, and hypothetical scale, requirements, or reuse.
- Trace the relevant callers and data flow before fixing a bug. Address the root cause in the appropriate shared location and avoid unrelated refactoring.
- Write comments only to explain non-obvious reasoning or constraints, never merely to restate the code. Use PHPDoc for useful type information, including array shapes.
- Keep the existing directory structure; do not introduce new base folders without approval.
- Do not add dependencies without explicit approval.

## Documentation and architecture

- Before Logistics changes, read [`docs/logistics/README.md`](docs/logistics/README.md), then use [`docs/logistics/plan/README.md`](docs/logistics/plan/README.md) to select the documents relevant to the change. Treat the consolidated plan as the target design; repository code and historical reports are evidence, not business authority.
- Before changing issue labels, phase tracking, Logistics or ERP Projects, or project automation, read [`docs/adr/0006-issue-and-project-tracking.md`](docs/adr/0006-issue-and-project-tracking.md). Use the owning Project's Phase field; do not create module-specific phase labels.
- Before making an architecture decision, check [`docs/adr/README.md`](docs/adr/README.md) and the relevant ADR. Record approved consequential decisions there and link them from the owning module documentation.
- Update relevant existing documentation when code changes affect documented behavior or decisions. Create new documentation files only when explicitly requested.

## Database platform and verification baseline

- The project is development-only, with no deployment configured and no production environment, as confirmed by the user on 2026-10-05. The `production` Git branch contains development work; its name does not establish an environment. See [the project lifecycle](docs/BRANCHING-AND-RELEASES.md#current-project-lifecycle-2026-10-05). Do not require a deployment database or deployment preflight to continue development. Apply deployment gates when a deployment target is established.
- PostgreSQL is the primary development and acceptance database. MySQL 8.0 is also a supported target, as approved on 2026-10-21. Keep both database lanes in CI. MariaDB is not included in this decision. Read [ADR 0008](docs/adr/0008-postgresql-primary-and-mysql-support.md); it supersedes the earlier PostgreSQL-only restriction in ADR 0007.
- Use the configured development database for authorized inspection and private databases for automated verification. Record the exact engine and version in each evidence report. Development data is not automatically disposable; inspect the actual database before applying schema changes to existing data.
- Verify affected shared migrations and business operations on both supported engines. PostgreSQL-specific SQL, locking, constraints and triggers require equivalent MySQL behavior; do not skip or weaken integrity controls to make an engine pass. Report unverified or failing MySQL paths explicitly. A passing PostgreSQL selection does not establish MySQL compatibility.
- PostgreSQL 16 and MySQL 8.0 are the current CI versions; PostgreSQL 18.1 is the recorded local test version. Existing PostgreSQL-specific Inventory, Accounts and Logistics paths have outstanding MySQL portability work. Keep that work visible and preserve both CI lanes rather than claiming that a configured matrix proves support.
- Use PHP 8.4 with a minimum patch version of 8.4.1, as required by composer.json. The current spreadsheet dependency excludes PHP 8.5. Check the locked requirements before changing PHP versions or the CI matrix.

## Git operations

Do not run destructive or history-changing Git operations unless the user explicitly authorizes the specific operation in a fresh message.

Without that authorization, do not run:

- `git commit` (including amend, reword, fixup, squash, or `--no-verify`) or `git push` (including force pushes and tags)
- `git rebase`, `git merge`, `git cherry-pick`, `git revert`, `git reset`, `git restore`, or switch/checkout to another branch or restore a path
- `git branch -d`/`-D`/`-M`, create or delete tags, or drop/clear stashes
- `git clean`, `git replace`, `git fast-import`, `git update-ref`, `git symbolic-ref`, history-rewriting tools, or pruning/expiring reflogs

Read-only `git status`, `git log`, `git diff`, `git show`, branch listing, remote listing, and reflog inspection are allowed, as is staging with `git add`. Preserve unrelated working-tree changes. Never add AI-agent attribution to commit messages or trailers.

## Commands and tools

- Prefix shell commands with `rtk` when it is installed.
- Use Laravel Boost tools when available. For version-specific Laravel ecosystem guidance, use Boost's `search-docs`; otherwise consult the official documentation for the installed version.
- Check Artisan command options with Boost's `list-artisan-commands` or `php artisan help <command>`. Use generators where they match the owning plugin's structure and pass `--no-interaction` to executable Artisan commands.
- Use Tinker for PHP debugging or model inspection, and database query/schema tools for read-only database inspection, when those tools are available.
- Use Boost's `get-absolute-url` for project URLs when available; otherwise verify the configured host, scheme, and port. Inspect recent browser logs when diagnosing UI errors.

### Common commands

Run these from the repository root, adding the `rtk` prefix when installed. Commands are a reference, not a checklist to execute for every task.

| Task | Command |
| --- | --- |
| Install locked PHP dependencies when needed | `composer install` |
| Install locked frontend dependencies when needed | `npm ci` |
| Run the combined local development processes | `composer run dev` |
| Run only Vite | `npm run dev` |
| Build frontend assets | `npm run build` |
| Run a specific test file | `php artisan test --compact path/to/Test.php` |
| Run a named test selection | `php artisan test --compact --filter='test name'` |
| Format task-owned PHP files | `vendor/bin/pint --format agent path/to/File.php` |

Dependency installation can execute project scripts. Check the target environment first; do not run installers, migrations, seeders, or database resets against application data as routine setup.

### Skills

- For plugin registration, installation lifecycle, panels, permissions, or resources, use [aureuserp-plugin-builder](.agents/skills/aureuserp-plugin-builder/SKILL.md).
- For plugin API feature tests, use [aureuserp-test-case-builder](.agents/skills/aureuserp-test-case-builder/SKILL.md).
- Use `pest-testing` for test implementation/debugging and `tailwindcss-development` for styling when available in the agent environment. If unavailable, follow repository patterns and version-matched official docs; do not invent a skill path.

## PHP and Laravel

- Use explicit parameter and return types and braces for control structures. Prefer constructor property promotion where suitable; avoid empty constructors unless a private constructor intentionally prevents instantiation.
- Use descriptive names and follow sibling enum, model cast, and namespace conventions. Avoid introducing parallel conventions within a module.
- Prefer Eloquent relationships and eager loading for ordinary model work. Use the query builder or `DB` facade when it makes complex, transactional, or set-based operations clearer.
- Validate and authorize at the relevant boundary. Use Form Requests for controller validation and the established validation APIs in Filament or Livewire components.
- Keep environment access in configuration files; use `config()` elsewhere.
- When modifying a database column, preserve its existing attributes in the migration.
- Follow existing API and resource conventions; do not introduce API versioning or scaffolding without a project need.
- Prefer named routes and Laravel's authentication and authorization mechanisms. Reuse existing factories and states; add factories or seeders when the feature or tests need them.

## Plugins, UI, and authorization

- Follow the owning plugin's service provider, Composer namespace, panel registration, and permission conventions. Check both admin and customer surfaces when a shared resource changes.
- Keep user-facing text in the plugin's existing translation structure. Reuse Filament components and existing Tailwind patterns before adding custom UI or JavaScript.
- Enforce authorization in the server-side action or policy, including company/record scope where applicable. Hiding a button is insufficient authorization.
- Preserve keyboard access, labels, validation feedback, and responsive behavior when changing UI. A successful asset build alone does not demonstrate that the interaction works.
- Do not expose credentials, tokens, or customer data in logs, documentation, or command output. Use dedicated test data for checks that write records.

## Testing, styling, and formatting

- Use Pest and follow the existing test setup. Add regression coverage for behavior changes and bug fixes; avoid tests that merely mirror the implementation.
- Inspect `tests/Pest.php`, `phpunit.xml`, and the owning plugin's tests before choosing a test command. Root tests may require an explicit file path because the configured suites list plugin directories.
- Feature tests use `DatabaseTransactions` and shared bootstrap helpers. Do not add `RefreshDatabase` or `LazilyRefreshDatabase` to individual tests.
- Read [tests/README.md](tests/README.md) before database-backed test runs: normal bootstrap can rebuild/install the ERP. Prepared-database reuse is opt-in, limited to the isolated test database, and must not be enabled for parallel workers.
- Run the smallest meaningful affected checks first. Broaden verification for shared changes or unresolved risks; report unrelated existing failures rather than weakening assertions or skipping tests to obtain a pass. Do not delete tests without approval.
- Run Pint on PHP files changed for the task. Do not format unrelated dirty files or the whole repository as a side effect.
- Do not create a separate verification script when the existing tests already cover the behavior.
- If a frontend change is not visible, check whether the project needs `npm run build`, `npm run dev`, or `composer run dev`.

## Replies

- Be concise and direct. Report what changed, which checks actually ran and their results, and any remaining risks or verification gaps. Distinguish observed facts from assumptions; never claim checks passed when they were not run.
- Recommend the next useful step when work remains. Do not describe focused tests or a build as full release acceptance.

## Maintaining these instructions

- Keep durable project rules, useful commands, and known pitfalls here. Link to detailed module docs and skills instead of copying their full workflows.
- Update instructions when the workflow changes. Resolve conflicting rules and verify commands against repository configuration; do not remove useful safeguards solely to meet a line-count target.
