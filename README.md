# Laravel Export Import

`agung/laravel-export-import` provides a background engine for CSV/XLSX exports and the foundation for import processing. The package stores the status of each request in the `export_import_operations` table, runs work through Laravel Queue, and exposes progress for application adapters to read.

The package has no built-in tenant or domain-specific rules. The application defines the actor, `context` contents, filter validation, authorization, status endpoint, and download endpoint.

## Requirements

- PHP 8.3 or later
- Laravel 12 or 13
- Laravel queue batch table (`job_batches`)
- An active queue worker

## Installation

Once the package is available through Composer:

```bash
composer require agung/laravel-export-import
```

For development inside a monorepo, register the path repository in the application's `composer.json`:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/laravel-export-import",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "agung/laravel-export-import": "*"
    }
}
```

Then run:

```bash
composer update agung/laravel-export-import
```

Laravel discovers `ExportImportServiceProvider` automatically. The package loads its migration and configuration. To publish the configuration so it can be changed in the application:

```bash
php artisan vendor:publish --tag=export-import-config
```

Run the package migration and make sure Laravel's queue batch migration is available:

```bash
php artisan migrate
```

If the `job_batches` table does not exist, create its migration using the command available in your Laravel version, then run the migration again.

## Configuration

The package's default configuration is available at `config/export-import.php` after publishing:

```php
use Agung\LaravelExportImport\ExportImportOperation;

return [
    'models' => [
        'operation' => ExportImportOperation::class,
    ],
    'tables' => [
        'operations' => 'export_import_operations',
    ],
    'storage' => [
        'disk' => 'local',
        'directory' => 'export-import',
    ],
    'queue' => [
        'connection' => null,
        'name' => null,
    ],
    'download' => [
        'route' => null,
        'parameter' => 'operation',
        'expiration_minutes' => 15,
    ],
];
```

- `tables.operations`: the operation status table name.
- `storage.disk` and `storage.directory`: the destination for export and upload files. The package does not make files public.
- `queue.connection` and `queue.name`: when `null`, Laravel's default connection and queue are used.
- `download.route`: the application route name for a signed download URL. The default value, `null`, means the package does not generate a download URL.
- `download.parameter` and `download.expiration_minutes`: the route parameter name and signed URL lifetime.

After changing cached configuration, run `php artisan config:clear`.

## Export flow

1. The application adapter validates the request, checks actor/tenant access, and calls `ExportImportEngine::startExport()`.
2. The engine creates an `ExportImportOperation` with `queued` status, stores the handler class, filter payload, application context, actor, and format, then dispatches `ProcessExportImportOperation` to the queue.
3. A worker calls the handler to get the query, counts the total rows, and creates one Laravel batch job for each chunk.
4. Each `ProcessExportImportChunk` reads rows using a bounded query and writes a temporary JSONL file for that chunk.
5. Laravel tracks completed batch jobs. Once every chunk succeeds, `FinalizeExportImportOperation` merges the temporary files into CSV/XLSX and changes the operation status to `completed`.
6. The adapter polls for status updates. When the export is complete, the adapter provides an authorized download URL.

Temporary chunks are stored on the filesystem, not in a database table. The `export_import_operations` table stores each operation's status, while Laravel's `job_batches` table tracks batch job progress during an export. After the file is finalized successfully, the package deletes the batch record and clears `batch_id` on the operation; the operation's progress remains at 100%.

The default chunk size targets about 10% of the total row count, with a minimum of 10 and a maximum of 100 rows per job. If the total is fewer than 10 rows, all rows are processed in one job. For example, 80 rows produce 8 jobs of 10 rows each; 1 million rows produce 10,000 jobs of 100 rows each. Export progress is calculated from the number of completed batch jobs.

Jobs can run in parallel. Exports with the same actor and `scopeKey` cannot overlap. Different actors can export concurrently. The application should provide a stable `scopeKey`, such as a tenant ID or a combination of domain and tenant.

## Create an export handler

Implement `ExportHandler` in the application. The handler is responsible for the query, the key used to divide the query, the headers, and mapping one record to one row:

```php
<?php

namespace App\Exports;

use Agung\LaravelExportImport\Contracts\ExportHandler;
use Agung\LaravelExportImport\ExportImportOperation;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class OrderExportHandler implements ExportHandler
{
    public function query(ExportImportOperation $operation): Builder
    {
        $filters = $operation->payload ?? [];

        return Order::query()
            ->where('shop_id', $operation->context['shop_id'])
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status) => $query->where('status', $status),
            )
            ->with('customer');
    }

    public function idColumn(): string
    {
        return 'orders.id';
    }

    public function headers(): array
    {
        return ['Order Number', 'Customer', 'Status', 'Total'];
    }

    public function mapRecord(Model $record): array
    {
        if (! $record instanceof Order) {
            throw new LogicException('Order export query must return orders.');
        }

        return [
            $record->number,
            $record->customer?->name,
            $record->status,
            $record->total,
        ];
    }
}
```

Make sure the query has a stable ordering/key, and that `idColumn()` points to the unique column used to divide ranges between jobs. The handler should eager-load relationships used by `mapRecord()` to avoid a query per row.

## Start an export from the application

The application controller or service validates input and authorizes the request before calling the engine:

```php
use Agung\LaravelExportImport\Enums\ExportFormat;
use Agung\LaravelExportImport\Services\ExportImportEngine;
use App\Exports\OrderExportHandler;
use App\Models\Shop;
use App\Models\User;

$user = User::query()->findOrFail($userId);
$shop = Shop::query()->findOrFail($shopId);

$operation = app(ExportImportEngine::class)->startExport(
    actor: $user,
    context: ['shop_id' => (string) $shop->getKey()],
    handler: OrderExportHandler::class,
    format: ExportFormat::Csv->value,
    fileName: 'orders.csv',
    payload: ['status' => 'paid'],
    scopeKey: (string) $shop->getKey(),
);
```

`actor` must be an Eloquent model or `null`. The engine uses the actor to scope operation lookups through `findOwnedOperation()`. `context` contains application data stored with the operation; the handler and application adapter must still apply the shop/tenant scope.

`startExport()` returns the newly created operation. An HTTP adapter typically responds with `202 Accepted` and `$engine->statusPayload($operation)`.

## Status, polling, and downloads

The status payload contains `id`, `type`, `status`, `processed_rows`, `total_rows`, `processed_jobs`, `total_jobs`, `progress_percentage`, `error_message`, `file_name`, and `download_url`. Terminal statuses are `completed` and `failed`; stop polling when either status is reached.

Example application status and download routes:

```php
Route::get('/export-import/{operation}', [ExportController::class, 'status'])
    ->middleware('auth')
    ->name('export-import.status');

Route::get('/export-import/{operation}/download', ExportDownloadController::class)
    ->middleware(['auth', 'signed'])
    ->name('export-import.download');
```

Enable signed download URLs with this configuration:

```php
'download' => [
    'route' => 'export-import.download',
    'parameter' => 'operation',
    'expiration_minutes' => 15,
],
```

The package provides `findOwnedOperation()`, `statusPayload()`, and `exportDownloadUrl()`, but does not provide a generic download route. The application's download controller must check actor and tenant/account ownership, confirm the operation is complete, and then stream the file from the private disk. Do not use a signed URL as a replacement for application authorization.

## Inertia integration

An Inertia adapter typically follows this pattern:

1. The POST endpoint validates the format and filters.
2. The endpoint gets the active actor and tenant/account from application middleware.
3. The endpoint calls `startExport()` with the appropriate handler and filters.
4. The frontend stores the operation `id` and polls the status endpoint using `useHttp`/Inertia.
5. When status is `completed`, the frontend uses `download_url`; when it is `failed`, it displays `error_message`.

The package does not provide an Inertia UI, export button, filters, format form, or polling implementation. Build these in the application to match its design.

Example of starting an order export from an Inertia controller:

```php
$operation = $engine->startExport(
    actor: $request->user(),
    context: ['shop_id' => (string) $shop->getKey()],
    handler: OrderExportHandler::class,
    format: $data['format'],
    fileName: 'orders-'.$shop->getKey().'.'.$data['format'],
    payload: $data,
    scopeKey: (string) $shop->getKey(),
);
```

The controller returns `statusPayload($operation)` with HTTP 202. The frontend uses the operation ID to poll for updates. The adapter must scope export, status, and download requests to the authenticated actor and active shop/tenant. `findOwnedOperation()` checks the actor; the adapter must also check the scope in `context` before returning a status or file.

## GraphQL integration

A GraphQL mutation follows the same pattern as an HTTP adapter: validate and authorize the actor and tenant, then call `startExport()` and return `statusPayload()`.

Example application mutation for starting an order export:

```graphql
mutation StartOrderExport($format: ExportFormat!, $status: String) {
  exportOrders(format: $format, status: $status) {
    id
    status
    progress_percentage
  }
}
```

Poll the operation:

```graphql
query ExportImportStatus($id: ID!) {
  exportImportOperation(id: $id) {
    id
    status
    processed_jobs
    total_jobs
    progress_percentage
    error_message
    download_url
  }
}
```

`exportOrders` and `exportImportOperation` in these examples are application schema fields, not resolvers provided by the package. Make sure the status resolver scopes the operation to the requesting actor and applies the application's shop/tenant scope.

## Import handler

`ImportHandler` extends `ExportImportHandler`. The engine stores the upload on the configured disk and dispatches the operation. The handler receives the operation and `ExportImportProgress`:

```php
use Agung\LaravelExportImport\Contracts\ImportHandler;
use Agung\LaravelExportImport\ExportImportOperation;
use Agung\LaravelExportImport\Services\ExportImportProgress;

final class CustomerImportHandler implements ImportHandler
{
    public function handle(ExportImportOperation $operation, ExportImportProgress $progress): void
    {
        $rows = $this->reader->read($operation->disk, $operation->file_path);
        $progress->setTotal(count($rows));

        foreach ($rows as $row) {
            $this->importCustomer($row, $operation->context);
            $progress->advance();
        }
    }
}
```

This example assumes the application provides `$reader` and `importCustomer()`; the package does not provide a parser or domain persistence workflow. `ExportImportProgress::setTotal()` sets the total row count, and `advance($rows)` increments the number of completed rows. Import handlers currently run as a single queue job, so avoid loading a large file into memory as in this simple example. The application should stream the file or implement an import chunking strategy.

## Application-specific operation model

The package migration intentionally does not include a tenant column. If the application needs `tenant_id` or other application-specific metadata in `export_import_operations`, add those columns in an application migration and create a model subclass for custom relationships or casts.

```php
use Agung\LaravelExportImport\ExportImportOperation as BaseExportImportOperation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'type', 'status', 'handler', 'format', 'disk', 'file_path', 'file_name', 'payload', 'processed_rows',
    'total_rows', 'error_message', 'started_at', 'completed_at', 'actor_id', 'actor_type', 'context',
    'active_export_key', 'batch_id', 'tenant_id',
])]
final class ExportImportOperation extends BaseExportImportOperation
{
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

Register the application model in `config/export-import.php`:

```php
'models' => [
    'operation' => App\Models\ExportImportOperation::class,
],
```

After calling the engine, associate the tenant with the operation in the same transaction:

```php
use Agung\LaravelExportImport\Enums\ExportFormat;
use Agung\LaravelExportImport\ExportImportOperation;
use App\Models\Tenant;
use App\Models\ExportImportOperation as ApplicationExportImportOperation;
use App\Services\ExportImport\Handlers\OrderExportHandler;
use Illuminate\Support\Facades\DB;
use LogicException;

$operation = DB::transaction(function () use ($engine, $actor, $tenant): ExportImportOperation {
    $operation = $engine->startExport(
        actor: $actor,
        context: [],
        handler: OrderExportHandler::class,
        format: ExportFormat::Csv->value,
        fileName: 'orders.csv',
        payload: [],
        scopeKey: (string) $tenant->getKey(),
    );

    if (! $operation instanceof ApplicationExportImportOperation) {
        throw new LogicException('Configure the application operation model.');
    }

    $operation->tenant()->associate($tenant);
    $operation->save();

    return $operation;
});
```

The engine dispatches jobs with `afterCommit`, so the worker receives the job only after the operation and its application-specific columns have been committed. Ensure the handler query, status endpoint, and download controller use these columns to enforce scope. This keeps the package migration reusable across applications while tenants remain in the schema and implementation of applications that need them.

## Queue worker

Run a Laravel worker for the configured connection/queue. For the default connection and queue:

```bash
php artisan queue:work
```

If the package uses a specific connection or queue, start a worker with matching values, for example:

```bash
php artisan queue:work database --queue=exports
```
