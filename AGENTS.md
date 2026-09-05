# Project Development Rules

## Project environment

- This project uses Laravel Sail.
- Run PHP, Artisan, Composer, and other PHP-related commands through Laravel Sail unless there is a specific reason not to.
- Inspect relevant existing code before making changes.
- After changes, run appropriate tests when possible.
- Do not read `.env` unless necessary.

## Normal development

- Normal code inspection and code edits may be performed automatically.
- Safe local tests and static analysis may be run automatically.
- Read-only Git commands such as `git status` and `git diff` may be run automatically.
- Do not ask for confirmation for ordinary code reading, editing, or safe local tests.

## Network and dependencies

- Do not enable or broaden network access without explicit user approval.
- Never add, remove, or update Composer or npm packages without explicit user approval.

## Git

- Before `git commit`, run `git status` and review the relevant diff.
- Summarize the changes and show the proposed commit message.
- Wait for explicit user approval before committing.
- Never run `git push`. Show the exact push command to the user instead.

## Destructive operations

- Never delete files or directories without explicit user approval.
- Treat `git reset`, `git clean`, `git restore`, force operations, database deletion, and Docker volume deletion as sensitive operations requiring approval.

## Production

- Never modify, deploy to, migrate, restart, or connect to production without explicit user approval.
