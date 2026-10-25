<?php

use Agung\LaravelExportImport\Contracts\ExportFileWriter;
use Agung\LaravelExportImport\ExportImportOperation;
use Agung\LaravelExportImport\Services\ExportFileWriterManager;
use Tests\TestCase;

uses(TestCase::class);

final class CustomExportFileWriterFake implements ExportFileWriter
{
    /**
     * Returns a placeholder path for a generated custom export.
     *
     * @param  list<string>  $headers
     * @param  iterable<string>  $chunkPaths
     */
    public function finalize(ExportImportOperation $operation, array $headers, iterable $chunkPaths): string
    {
        return 'exports/'.$operation->getKey().'.custom';
    }
}

it('resolves an application writer for a custom format', function () {
    config()->set('export-import.export.writers.custom', CustomExportFileWriterFake::class);

    expect(app(ExportFileWriterManager::class)->forFormat('custom'))
        ->toBeInstanceOf(CustomExportFileWriterFake::class);
});

it('throws when no writer is configured for a format', function () {
    expect(fn () => app(ExportFileWriterManager::class)->forFormat('custom'))
        ->toThrow(InvalidArgumentException::class, 'No export writer is configured for format [custom].');
});
