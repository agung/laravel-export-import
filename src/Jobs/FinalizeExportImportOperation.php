<?php

namespace Agung\LaravelExportImport\Jobs;

use Agung\LaravelExportImport\Contracts\ExportHandler;
use Agung\LaravelExportImport\Enums\ExportImportStatus;
use Agung\LaravelExportImport\ExportImportOperation;
use Agung\LaravelExportImport\Services\ExportChunkSizer;
use Agung\LaravelExportImport\Services\ExportFileWriterManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Throwable;

class FinalizeExportImportOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $operationId)
    {
    }

    public function handle(ExportFileWriterManager $writerManager, ExportChunkSizer $chunkSizer): void
    {
        $operation = ExportImportOperation::newConfiguredQuery()->findOrFail($this->operationId);
        $exporter = app($operation->handler);

        if (! $exporter instanceof ExportHandler) {
            throw new LogicException('Export handler must implement ExportHandler.');
        }

        $batch = $operation->batch_id ? Bus::findBatch($operation->batch_id) : null;
        $chunkCount = $batch?->totalJobs
            ?? $chunkSizer->chunkCountForTotalRows((int) $operation->total_rows);

        try {
            $writer = $writerManager->forFormat($operation->format);
            $filePath = $writer->finalize(
                $operation,
                $exporter->headers(),
                $this->chunkPaths($operation, $chunkCount),
            );

            $batch?->delete();

            $operation->forceFill([
                'file_path' => $filePath,
                'processed_rows' => $operation->total_rows,
                'status' => ExportImportStatus::Completed,
                'completed_at' => now(),
                'active_export_key' => null,
                'batch_id' => null,
            ])->save();

            Storage::disk($operation->disk)->deleteDirectory(
                sprintf(
                    '%s/exports/%s/chunks',
                    trim((string) config('export-import.storage.directory', 'export-import'), '/'),
                    $operation->getKey(),
                ),
            );
        } catch (Throwable $exception) {
            $operation->forceFill([
                'status' => ExportImportStatus::Failed,
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
                'active_export_key' => null,
            ])->save();

            Storage::disk($operation->disk)->deleteDirectory(
                sprintf(
                    '%s/exports/%s',
                    trim((string) config('export-import.storage.directory', 'export-import'), '/'),
                    $operation->getKey(),
                ),
            );

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        ExportImportOperation::newConfiguredQuery()->whereKey($this->operationId)->update([
            'status' => ExportImportStatus::Failed->value,
            'error_message' => mb_substr($exception?->getMessage() ?? 'Export finalization failed.', 0, 5000),
            'completed_at' => now(),
            'active_export_key' => null,
        ]);

        $operation = ExportImportOperation::newConfiguredQuery()->find($this->operationId);
        if ($operation) {
            Storage::disk($operation->disk)->deleteDirectory(
                sprintf(
                    '%s/exports/%s',
                    trim((string) config('export-import.storage.directory', 'export-import'), '/'),
                    $operation->getKey(),
                ),
            );
        }
    }

    /**
     * Builds the deterministic temporary-file paths for all dispatched chunks.
     *
     * @return iterable<string>
     */
    private function chunkPaths(ExportImportOperation $operation, int $chunkCount): iterable
    {
        $directory = trim((string) config('export-import.storage.directory', 'export-import'), '/');

        for ($chunkNumber = 0; $chunkNumber < $chunkCount; $chunkNumber++) {
            yield sprintf(
                '%s/exports/%s/chunks/%s.jsonl',
                $directory,
                $operation->getKey(),
                $chunkNumber,
            );
        }
    }
}
