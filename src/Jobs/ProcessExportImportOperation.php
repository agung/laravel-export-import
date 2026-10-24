<?php

namespace Agung\LaravelExportImport\Jobs;

use Agung\LaravelExportImport\Contracts\ExportHandler;
use Agung\LaravelExportImport\Contracts\ExportImportHandler;
use Agung\LaravelExportImport\Enums\ExportImportStatus;
use Agung\LaravelExportImport\ExportImportOperation;
use Agung\LaravelExportImport\Services\ExportChunkSizer;
use Agung\LaravelExportImport\Services\ExportImportProgress;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

class ProcessExportImportOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public function __construct(public int $operationId) {}

    public function handle(ExportChunkSizer $chunkSizer): void
    {
        $operation = ExportImportOperation::newConfiguredQuery()->findOrFail($this->operationId);
        $operation->forceFill([
            'status' => ExportImportStatus::Processing,
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        try {
            $handler = app($operation->handler);
            if ($operation->type->value === 'export' && $handler instanceof ExportHandler) {
                $this->dispatchExportChunks($operation, $handler, $chunkSizer);

                return;
            }

            if (! $handler instanceof ExportImportHandler) {
                throw new LogicException('Export/import handler must implement ExportImportHandler.');
            }

            $handler->handle($operation, new ExportImportProgress($operation));
            $operation->forceFill([
                'status' => ExportImportStatus::Completed,
                'completed_at' => now(),
                'active_export_key' => null,
            ])->save();
        } catch (Throwable $exception) {
            $operation->forceFill([
                'status' => ExportImportStatus::Failed,
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
                'active_export_key' => null,
            ])->save();

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ExportImportOperation::newConfiguredQuery()->whereKey($this->operationId)->update([
            'status' => ExportImportStatus::Failed->value,
            'error_message' => mb_substr($exception?->getMessage() ?? 'Operation failed.', 0, 5000),
            'completed_at' => now(),
            'active_export_key' => null,
        ]);
        $this->deleteTemporaryFiles();
    }

    private function dispatchExportChunks(
        ExportImportOperation $operation,
        ExportHandler $exporter,
        ExportChunkSizer $chunkSizer,
    ): void {
        $query = $exporter->query($operation);
        $idColumn = $exporter->idColumn();
        $total = (clone $query)->reorder()->count($idColumn);
        $operation->forceFill(['total_rows' => $total])->save();

        if ($total === 0) {
            self::dispatchFinalizer($operation->id);

            return;
        }

        $chunkCount = $chunkSizer->chunkCountForTotalRows($total);
        $jobs = [];
        $lowerId = null;
        for ($chunkNumber = 0; $chunkNumber < $chunkCount; $chunkNumber++) {
            $chunkSize = $chunkSizer->rowsForChunk($total, $chunkNumber);
            $idQuery = (clone $query)->reorder();
            if ($lowerId !== null) {
                $idQuery->where($idColumn, '>', $lowerId);
            }

            $ids = $idQuery->orderBy($idColumn)->limit($chunkSize)->pluck($idColumn);
            if ($ids->isEmpty()) {
                break;
            }

            $upperId = (int) $ids->last();
            $jobs[] = new ProcessExportImportChunk(
                $operation->id,
                $chunkNumber,
                $lowerId,
                $upperId,
            );

            $lowerId = $upperId;
        }

        if ($jobs === []) {
            self::dispatchFinalizer($operation->id);

            return;
        }

        $operationId = $operation->getKey();
        $batch = Bus::batch($jobs)
            ->name('Export/import operation '.$operation->getKey())
            ->before(static function (Batch $batch) use ($operation): void {
                $operation->forceFill(['batch_id' => $batch->id])->save();
            })
            ->allowFailures(false)
            ->then(static function (Batch $batch) use ($operationId): void {
                self::dispatchFinalizer($operationId);
            })
            ->catch(static function (Batch $batch, Throwable $exception) use ($operationId): void {
                DB::transaction(function () use ($operationId, $exception): void {
                    ExportImportOperation::newConfiguredQuery()->whereKey($operationId)->update([
                        'status' => ExportImportStatus::Failed->value,
                        'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                        'completed_at' => now(),
                        'active_export_key' => null,
                    ]);
                });
                $directory = trim((string) config('export-import.storage.directory', 'export-import'), '/');
                Storage::disk((string) config('export-import.storage.disk', 'local'))
                    ->deleteDirectory("{$directory}/exports/{$operationId}");
            });

        $connection = config('export-import.queue.connection');
        $queue = config('export-import.queue.name');

        if (is_string($connection) && $connection !== '') {
            $batch->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $batch->onQueue($queue);
        }

        $batch->dispatch();
    }

    private static function dispatchFinalizer(int $operationId): void
    {
        $job = new FinalizeExportImportOperation($operationId);
        $connection = config('export-import.queue.connection');
        $queue = config('export-import.queue.name');

        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }

        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    private function deleteTemporaryFiles(): void
    {
        $directory = trim((string) config('export-import.storage.directory', 'export-import'), '/');
        Storage::disk((string) config('export-import.storage.disk', 'local'))
            ->deleteDirectory("{$directory}/exports/{$this->operationId}");
    }
}
