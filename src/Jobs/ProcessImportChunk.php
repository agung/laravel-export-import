<?php

namespace Agung\LaravelExportImport\Jobs;

use Agung\LaravelExportImport\Contracts\ChunkedImportHandler;
use Agung\LaravelExportImport\Enums\ExportImportStatus;
use Agung\LaravelExportImport\ExportImportOperation;
use Agung\LaravelExportImport\Services\ExportImportProgress;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use LogicException;
use Throwable;

class ProcessImportChunk implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 80;

    public function __construct(public int $operationId, public int $chunkNumber) {}

    /**
     * Prevents duplicate workers from processing the same import chunk concurrently.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("export-import:{$this->operationId}:import:{$this->chunkNumber}"))
                ->releaseAfter(5)
                ->expireAfter(900),
        ];
    }

    /**
     * Defines retry delays for transient chunk failures.
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(): void
    {
        $operation = ExportImportOperation::newConfiguredQuery()->findOrFail($this->operationId);

        if ($operation->status !== ExportImportStatus::Processing) {
            return;
        }

        $handler = app($operation->handler);

        if (! $handler instanceof ChunkedImportHandler) {
            throw new LogicException('Import handler must implement ChunkedImportHandler.');
        }

        $handler->handleChunk(
            $operation,
            $this->chunkNumber,
            new ExportImportProgress($operation),
        );
    }

    public function failed(?Throwable $exception): void
    {
        ExportImportOperation::newConfiguredQuery()->whereKey($this->operationId)->update([
            'status' => ExportImportStatus::Failed->value,
            'error_message' => mb_substr($exception?->getMessage() ?? 'Import chunk failed.', 0, 5000),
            'completed_at' => now(),
            'active_export_key' => null,
        ]);
    }
}
