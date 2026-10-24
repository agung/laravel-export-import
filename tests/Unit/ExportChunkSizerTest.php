<?php

use Agung\LaravelExportImport\Services\ExportChunkSizer;

it('returns no chunks when the total row count is not positive', function (int $totalRows) {
    $sizer = new ExportChunkSizer;

    expect($sizer->chunkCountForTotalRows($totalRows))->toBe(0)
        ->and($sizer->rowsForChunk($totalRows, 0))->toBe(0);
})->with([-5, 0]);

it('keeps totals smaller than the minimum in one chunk', function (int $totalRows) {
    $sizer = new ExportChunkSizer;

    expect($sizer->chunkCountForTotalRows($totalRows))->toBe(1)
        ->and($sizer->rowsForChunk($totalRows, 0))->toBe($totalRows);
})->with([1, 5, 9]);

it('uses ten rows per chunk when ten percent is below the minimum', function () {
    $sizer = new ExportChunkSizer;

    expect($sizer->chunkCountForTotalRows(80))->toBe(8)
        ->and($sizer->rowsForChunk(80, 0))->toBe(10)
        ->and($sizer->rowsForChunk(80, 7))->toBe(10);
});

it('distributes remainder rows across the first chunks', function () {
    $sizer = new ExportChunkSizer;
    $chunkSizes = array_map(
        fn (int $chunkNumber): int => $sizer->rowsForChunk(101, $chunkNumber),
        range(0, 9),
    );

    expect($sizer->chunkCountForTotalRows(101))->toBe(10)
        ->and($chunkSizes)->toBe([11, 10, 10, 10, 10, 10, 10, 10, 10, 10])
        ->and(array_sum($chunkSizes))->toBe(101);
});

it('keeps large exports within the maximum rows per chunk', function () {
    $sizer = new ExportChunkSizer;

    expect($sizer->chunkCountForTotalRows(1_000_000))->toBe(10_000)
        ->and($sizer->rowsForChunk(1_000_000, 0))->toBe(100)
        ->and($sizer->rowsForChunk(1_000_000, 9_999))->toBe(100);
});

it('returns zero for chunk indexes outside the chunk range', function (int $chunkNumber) {
    $sizer = new ExportChunkSizer;

    expect($sizer->rowsForChunk(80, $chunkNumber))->toBe(0);
})->with([-1, 8]);
