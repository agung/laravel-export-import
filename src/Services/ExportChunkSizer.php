<?php

namespace Agung\LaravelExportImport\Services;

final class ExportChunkSizer
{
    private const int CHUNK_PROGRESS_PERCENT = 10;

    private const int MIN_ROWS_PER_CHUNK = 10;

    private const int MAX_ROWS_PER_CHUNK = 100;

    public function chunkCountForTotalRows(int $totalRows): int
    {
        if ($totalRows <= 0) {
            return 0;
        }

        return (int) ceil($totalRows / $this->targetRowsPerChunk($totalRows));
    }

    public function rowsForChunk(int $totalRows, int $chunkNumber): int
    {
        $chunkCount = $this->chunkCountForTotalRows($totalRows);
        if ($chunkCount === 0 || $chunkNumber < 0 || $chunkNumber >= $chunkCount) {
            return 0;
        }

        $baseSize = intdiv($totalRows, $chunkCount);
        $largerChunkCount = $totalRows % $chunkCount;
        $chunkSize = $baseSize + ($chunkNumber < $largerChunkCount ? 1 : 0);

        return $totalRows < self::MIN_ROWS_PER_CHUNK
            ? $totalRows
            : $chunkSize;
    }

    private function targetRowsPerChunk(int $totalRows): int
    {
        $targetSize = (int) ceil($totalRows * self::CHUNK_PROGRESS_PERCENT / 100);

        return min(self::MAX_ROWS_PER_CHUNK, max(self::MIN_ROWS_PER_CHUNK, $targetSize));
    }
}
