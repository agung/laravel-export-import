<?php

namespace Agung\LaravelExportImport\Services;

use Agung\LaravelExportImport\Contracts\ExportHandler;
use Agung\LaravelExportImport\Contracts\ExportImportHandler;
use Agung\LaravelExportImport\Contracts\ImportHandler;
use Agung\LaravelExportImport\Enums\ExportImportStatus;
use Agung\LaravelExportImport\Enums\ExportImportType;
use Agung\LaravelExportImport\ExportImportOperation;
use Agung\LaravelExportImport\Jobs\ProcessExportImportOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

final class ExportImportEngine
{
    /**
     * Creates and queues an export operation for the supplied handler and filters.
     *
     * @param  class-string<ExportHandler>  $handler
     * @param  array<string, mixed>  $payload
     */
    public function startExport(
        ?Model $actor,
        array $context,
        string $handler,
        string $format,
        string $fileName,
        array $payload,
        ?string $scopeKey = null,
    ): ExportImportOperation {
        if (! is_subclass_of($handler, ExportHandler::class)) {
            throw new InvalidArgumentException('Export handler must implement ExportHandler.');
        }

        return $this->start(
            $actor,
            $context,
            ExportImportType::Export,
            $handler,
            $payload,
            $scopeKey,
            $format,
            $fileName,
        );
    }

    /**
     * Stores an uploaded file and queues it for the supplied import handler.
     *
     * @param  class-string<ImportHandler>  $handler
     * @param  array<string, mixed>  $payload
     */
    public function startImport(
        ?Model $actor,
        array $context,
        string $handler,
        UploadedFile $file,
        array $payload = [],
        ?string $scopeKey = null,
    ): ExportImportOperation {
        if (! is_subclass_of($handler, ImportHandler::class)) {
            throw new InvalidArgumentException('Import handler must implement ImportHandler.');
        }

        $fileName = basename($file->getClientOriginalName());
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $extension = preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? $extension : 'bin';
        $directory = trim((string) config('export-import.storage.directory', 'export-import'), '/');
        $path = $file->storeAs(
            "{$directory}/uploads",
            Str::uuid().'.'.$extension,
            (string) config('export-import.storage.disk', 'local'),
        );

        if (! $path) {
            throw new InvalidArgumentException('Unable to store uploaded file.');
        }

        $operation = $this->start(
            $actor,
            $context,
            ExportImportType::Import,
            $handler,
            $payload,
            $scopeKey,
            null,
            $fileName,
            $path,
        );

        return $operation;
    }

    public function findOwnedOperation(
        int|string $id,
        Model $actor,
    ): ExportImportOperation {
        $query = ExportImportOperation::newConfiguredQuery()->whereKey($id);

        $query->where('actor_type', $actor->getMorphClass())
            ->where('actor_id', $actor->getKey());

        return $query->firstOrFail();
    }

    public function exportDownloadUrl(ExportImportOperation $operation): ?string
    {
        if ($operation->type !== ExportImportType::Export
            || $operation->status !== ExportImportStatus::Completed
            || ! $operation->file_path) {
            return null;
        }

        $route = config('export-import.download.route');
        if (! is_string($route) || $route === '') {
            return null;
        }

        return URL::temporarySignedRoute(
            $route,
            now()->addMinutes((int) config('export-import.download.expiration_minutes', 15)),
            [config('export-import.download.parameter', 'operation') => $operation->getKey()],
        );
    }

    public function deleteExportFile(ExportImportOperation $operation): void
    {
        if ($operation->file_path) {
            Storage::disk($operation->disk)->delete($operation->file_path);
        }
    }

    /**
     * Returns the public status payload for polling clients.
     *
     * @return array<string, mixed>
     */
    public function statusPayload(ExportImportOperation $operation): array
    {
        $batch = $operation->batch_id ? Bus::findBatch($operation->batch_id) : null;

        return [
            'id' => (string) $operation->getKey(),
            'type' => $operation->type->value,
            'status' => $operation->status->value,
            'processed_rows' => $operation->processed_rows,
            'total_rows' => $operation->total_rows,
            'processed_jobs' => $batch?->processedJobs(),
            'total_jobs' => $batch?->totalJobs,
            'progress_percentage' => $operation->progressPercentage(),
            'error_message' => $operation->error_message,
            'file_name' => $operation->file_name,
            'download_url' => $this->exportDownloadUrl($operation),
            'started_at' => $operation->started_at,
            'completed_at' => $operation->completed_at,
        ];
    }

    private function hasActiveExport(string $activeExportKey): bool
    {
        return ExportImportOperation::newConfiguredQuery()
            ->where('active_export_key', $activeExportKey)
            ->where('type', ExportImportType::Export->value)
            ->whereIn('status', [
                ExportImportStatus::Queued->value,
                ExportImportStatus::Processing->value,
            ])
            ->exists();
    }

    /**
     * Persists a queued operation and dispatches its initial worker job.
     *
     * @param  class-string<ExportImportHandler|ExportHandler>  $handler
     * @param  array<string, mixed>  $payload
     */
    private function start(
        ?Model $actor,
        array $context,
        ExportImportType $type,
        string $handler,
        array $payload,
        ?string $scopeKey,
        ?string $format,
        ?string $fileName,
        ?string $filePath = null,
    ): ExportImportOperation {
        $validHandler = $type === ExportImportType::Export
            ? is_subclass_of($handler, ExportHandler::class)
            : is_subclass_of($handler, ExportImportHandler::class);
        if (! $validHandler) {
            throw new InvalidArgumentException('Handler must implement ExportImportHandler.');
        }

        $activeExportKey = $type === ExportImportType::Export && $actor !== null
            ? hash('sha256', implode('|', [
                $scopeKey ?? '',
                $actor->getMorphClass(),
                (string) $actor->getKey(),
            ]))
            : null;

        if ($activeExportKey !== null && $this->hasActiveExport($activeExportKey)) {
            throw ValidationException::withMessages([
                'export' => 'An export is already active for this owner and scope.',
            ]);
        }

        try {
            $operation = ExportImportOperation::newConfiguredQuery()->create([
                'type' => $type,
                'status' => ExportImportStatus::Queued,
                'handler' => $handler,
                'format' => $format,
                'disk' => (string) config('export-import.storage.disk', 'local'),
                'file_path' => $filePath,
                'file_name' => $fileName,
                'payload' => $payload,
                'context' => $context,
                'actor_id' => $actor?->getKey(),
                'actor_type' => $actor?->getMorphClass(),
                'active_export_key' => $activeExportKey,
            ]);
        } catch (QueryException $exception) {
            if ($activeExportKey !== null && $this->hasActiveExport($activeExportKey)) {
                throw ValidationException::withMessages([
                    'export' => 'An export is already active for this owner and scope.',
                ]);
            }

            throw $exception;
        }

        try {
            $job = new ProcessExportImportOperation($operation->id);
            $connection = config('export-import.queue.connection');
            $queue = config('export-import.queue.name');

            if (is_string($connection) && $connection !== '') {
                $job->onConnection($connection);
            }

            if (is_string($queue) && $queue !== '') {
                $job->onQueue($queue);
            }

            dispatch($job)->afterCommit();
        } catch (Throwable $exception) {
            $operation->forceFill([
                'status' => ExportImportStatus::Failed,
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
                'active_export_key' => null,
            ])->save();

            throw $exception;
        }

        return $operation;
    }
}
