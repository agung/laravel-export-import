<?php

namespace Agung\LaravelExportImport\Jobs;

use Agung\LaravelExportImport\Contracts\ChunkedImportHandler;
use Agung\LaravelExportImport\Enums\ExportImportStatus;
use Agung\LaravelExportImport\ExportImportOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use LogicException;
use Throwable;

class FinalizeImportOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public function __construct(public int $operationId) {}

    public function handle(): void
    {
        $operation = ExportImportOperation::newConfiguredQuery()->findOrFail($this->operationId);
        $handler = app($operation->handler);

        if (! $handler instanceof ChunkedImportHandler) {
            throw new LogicException('Import handler must implement ChunkedImportHandler.');
        }

        $batch = $operation->batch_id ? Bus::findBatch($operation->batch_id) : null;
        $handler->cleanupChunks($operation);
        $batch?->delete();

        $operation->forceFill([
            'processed_rows' => $operation->total_rows,
            'status' => ExportImportStatus::Completed,
            'completed_at' => now(),
            'active_export_key' => null,
            'batch_id' => null,
            'context' => array_diff_key($operation->context ?? [], ['import_phase' => true]),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        $operation = ExportImportOperation::newConfiguredQuery()->find($this->operationId);

        if (! $operation) {
            return;
        }

        if ($operation->batch_id) {
            Bus::findBatch($operation->batch_id)?->delete();
        }

        $operation->forceFill([
            'status' => ExportImportStatus::Failed,
            'error_message' => mb_substr($exception?->getMessage() ?? 'Import finalization failed.', 0, 5000),
            'completed_at' => now(),
            'active_export_key' => null,
        ])->save();

        $handler = app($operation->handler);

        if ($handler instanceof ChunkedImportHandler) {
            $handler->cleanupChunks($operation);
        }
    }
}
