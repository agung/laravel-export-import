# Laravel Export Import Package Rules

These instructions apply to changes inside this package. Keep the package framework-neutral where possible; application-specific concerns such as tenants, actors, and domain filters belong in the consuming application.

## PHP style

- Keep PHP source lines at or below 120 characters. Break long arrays, calls, method chains, closures, and conditions across multiple lines.
- Import referenced classes with `use` statements. Do not use fully qualified class names inline when an import is appropriate.
- When writing a PHPDoc block, put a useful description on the line after `/**`. Add a blank line before tags such as `@param` and `@return`. Do not write a tags-only block when a short description would explain the method.
- After a multi-line control block or other multi-line statement, add exactly one blank line before the next statement or declaration.
- For multi-line `if` conditions, put the opening brace on the last condition line and keep the body inside braces. Follow this layout:

  ```php
  if ($operation->type !== ExportImportType::Export
              || $operation->status !== ExportImportStatus::Completed
              || ! $operation->file_path) {
              return null;
          }

  $filePath = $operation->file_path;
  ```

- Use explicit parameter and return types, and braces for every control structure.
- After PHP changes, run `vendor/bin/pint --dirty --format agent` and verify line lengths. Avoid reformatting unrelated user changes; pass only the changed PHP paths to Pint when the dirty worktree includes unrelated files.

## Package extensibility

- Keep package migrations and models free of application-specific columns and relations.
- Add extension points through documented configuration keys or contracts. If an application needs a custom operation model, resolve the configured model consistently in the engine and every job.
- Do not mark a class `final` if applications are expected to extend it. Keep internal classes `final` only when they are not extension points; prefer explicit configuration or contracts over undocumented subclassing.
- Write all package unit and feature tests with Pest. Activate the `pest-testing` skill whenever adding or changing tests.
- Prefer unit tests for isolated sizing and mapping behavior, and feature tests for engine, database, and queue integration.
- Add focused coverage for new extension behavior and run affected tests with `php artisan test --compact <test-file>`.
- Keep tenant migrations, tenant model relations, authorization, and tenant scoping in the consuming application.
