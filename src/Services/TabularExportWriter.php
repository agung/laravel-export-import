<?php

namespace Agung\LaravelExportImport\Services;

use Agung\LaravelExportImport\Enums\ExportFormat;
use Agung\LaravelExportImport\ExportImportOperation;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\AbstractWriter;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Throwable;

final class TabularExportWriter
{
    private const int QUERY_BATCH_SIZE = 100;

    /**
     * Writes one bounded export chunk to temporary storage for later assembly.
     *
     * @param  Closure(Model): list<mixed>  $mapRecord
     * @return array{file_path: string, processed_rows: int}
     */
    public function writeChunk(
        ExportImportOperation $operation,
        int $chunkNumber,
        Builder $query,
        string $idColumn,
        Closure $mapRecord,
    ): array {
        $disk = Storage::disk($operation->disk);
        $directory = sprintf(
            '%s/exports/%s/chunks',
            trim((string) config('export-import.storage.directory', 'export-import'), '/'),
            $operation->getKey(),
        );

        $disk->makeDirectory($directory);
        $relativePath = "{$directory}/{$chunkNumber}.jsonl";
        $temporaryPath = tempnam(sys_get_temp_dir(), 'export-chunk-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create temporary export chunk.');
        }

        $stream = fopen($temporaryPath, 'wb');

        if ($stream === false) {
            unlink($temporaryPath);
            throw new RuntimeException('Unable to open temporary export chunk.');
        }

        $count = 0;

        try {
            $query->reorder()->chunkById(
                self::QUERY_BATCH_SIZE,
                function ($records) use ($stream, $mapRecord, &$count): void {
                    foreach ($records as $record) {
                        $line = json_encode(
                            $this->safeCells($mapRecord($record)),
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                        );

                        if (fwrite($stream, $line."\n") === false) {
                            throw new RuntimeException('Unable to write export chunk row.');
                        }
                    }

                    $count += $records->count();
                },
                column: $idColumn,
                alias: 'id',
            );

            fclose($stream);
            $stream = null;
            $upload = fopen($temporaryPath, 'rb');
            if ($upload === false || ! $disk->put($relativePath, $upload)) {
                if (is_resource($upload)) {
                    fclose($upload);
                }

                throw new RuntimeException('Unable to finalize export chunk.');
            }

            fclose($upload);
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $exception;
        } finally {
            unlink($temporaryPath);
        }

        return ['file_path' => $relativePath, 'processed_rows' => $count];
    }

    /**
     * Merges completed chunk files into the requested CSV or XLSX export.
     *
     * @param  list<string>  $headers
     * @param  iterable<string>  $chunkPaths
     */
    public function finalize(ExportImportOperation $operation, array $headers, iterable $chunkPaths): string
    {
        $disk = Storage::disk($operation->disk);
        $format = ExportFormat::from($operation->format);
        $directory = trim((string) config('export-import.storage.directory', 'export-import'), '/');
        $relativePath = "{$directory}/exports/{$operation->getKey()}.{$format->value}";
        $temporaryPath = tempnam(sys_get_temp_dir(), 'export-file-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create temporary export file.');
        }

        $disk->makeDirectory("{$directory}/exports");
        $writer = $this->writer($format);
        $writer->openToFile($temporaryPath);
        $writerOpen = true;

        try {
            $writer->addRow(Row::fromValues($this->safeCells($headers)));

            foreach ($chunkPaths as $chunkPath) {
                $stream = $disk->readStream($chunkPath);
                if ($stream === false) {
                    throw new RuntimeException('Unable to read an export chunk.');
                }

                try {
                    while (($line = fgets($stream)) !== false) {
                        $values = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                        $writer->addRow(Row::fromValues($this->safeCells($values)));
                    }
                } finally {
                    fclose($stream);
                }
            }

            $writer->close();
            $writerOpen = false;
            $upload = fopen($temporaryPath, 'rb');
            if ($upload === false || ! $disk->put($relativePath, $upload)) {
                if (is_resource($upload)) {
                    fclose($upload);
                }

                throw new RuntimeException('Unable to finalize export file.');
            }

            fclose($upload);
        } catch (Throwable $exception) {
            if ($writerOpen) {
                $writer->close();
            }

            $disk->delete($relativePath);

            throw $exception;
        } finally {
            unlink($temporaryPath);
        }

        return $relativePath;
    }

    private function writer(ExportFormat $format): AbstractWriter
    {
        return match ($format) {
            ExportFormat::Csv => new CsvWriter,
            ExportFormat::Xlsx => new XlsxWriter,
        };
    }

    /**
     * Converts row values to safe scalar cells accepted by the spreadsheet writer.
     *
     * @param  list<mixed>  $values
     * @return list<bool|float|int|string|null>
     */
    private function safeCells(array $values): array
    {
        return array_map(static function (mixed $value): bool|float|int|string|null {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }

            if (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
                return "'{$value}";
            }

            return is_scalar($value) || $value === null ? $value : (string) $value;
        }, array_values($values));
    }
}
