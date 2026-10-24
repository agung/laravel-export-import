# Laravel Export Import

`agung/laravel-export-import` menyediakan engine background untuk export CSV/XLSX dan fondasi pemrosesan import. Package ini menyimpan status tiap request pada tabel `export_import_operations`, menjalankan pekerjaan melalui Laravel Queue, dan menyediakan progress yang bisa dibaca adapter aplikasi.

Package ini tidak memiliki konsep tenant atau aturan domain tertentu. Aplikasi menentukan actor, isi `context`, validasi filter, otorisasi, endpoint status, dan endpoint download.

## Persyaratan

- PHP 8.3 atau lebih baru
- Laravel 12 atau 13
- Queue batch table Laravel (`job_batches`)
- Queue worker yang aktif

## Instalasi

Jika package sudah dipublikasikan ke Composer:

```bash
composer require agung/laravel-export-import
```

Untuk pengembangan dalam monorepo, daftarkan path repository di `composer.json` aplikasi:

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

Kemudian jalankan:

```bash
composer update agung/laravel-export-import
```

Laravel menemukan `ExportImportServiceProvider` melalui package discovery. Package memuat migration dan konfigurasi secara otomatis. Untuk menyalin konfigurasi ke aplikasi agar bisa diubah:

```bash
php artisan vendor:publish --tag=export-import-config
```

Jalankan migration package dan pastikan migration queue batch Laravel sudah tersedia:

```bash
php artisan migrate
```

Jika tabel `job_batches` belum ada, buat migration batch table menggunakan command Laravel yang tersedia di versi aplikasi, lalu jalankan migration lagi.

## Konfigurasi

Konfigurasi default package berada di `config/export-import.php` setelah dipublikasikan:

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

- `tables.operations`: nama tabel status operation.
- `storage.disk` dan `storage.directory`: tujuan file export dan upload. File tidak dibuat public oleh package.
- `queue.connection` dan `queue.name`: jika `null`, Laravel memakai koneksi dan queue default.
- `download.route`: nama route aplikasi untuk signed download URL. Nilai default `null` berarti package tidak membuat download URL.
- `download.parameter` dan `download.expiration_minutes`: nama parameter route dan masa berlaku signed URL.

Setelah mengubah konfigurasi yang di-cache, jalankan `php artisan config:clear`.

## Alur export

1. Adapter aplikasi memvalidasi request, memeriksa akses actor/tenant, lalu memanggil `ExportImportEngine::startExport()`.
2. Engine membuat `ExportImportOperation` berstatus `queued`, menyimpan class handler, payload filter, context aplikasi, actor, dan format, lalu mengantrekan `ProcessExportImportOperation`.
3. Worker memanggil handler untuk mendapatkan query, menghitung total row, dan membuat satu Laravel batch job untuk setiap chunk.
4. Setiap `ProcessExportImportChunk` membaca row menggunakan query terbatas dan menulis file sementara JSONL untuk chunk tersebut.
5. Laravel mencatat jumlah job batch yang selesai. Setelah semua chunk berhasil, `FinalizeExportImportOperation` menggabungkan file sementara menjadi CSV/XLSX dan mengubah status menjadi `completed`.
6. Adapter membaca status secara polling. Setelah selesai, adapter menyediakan download URL yang sudah diotorisasi.

Chunk sementara disimpan di filesystem, bukan di tabel database. Tabel `export_import_operations` menyimpan status satu operation, sedangkan tabel Laravel `job_batches` menyimpan progress job batch saat export berjalan. Setelah file berhasil difinalisasi, package menghapus record batch dan mengosongkan `batch_id` pada operation; persentase operation selesai tetap 100%.

Ukuran chunk bawaan menargetkan sekitar 10% dari jumlah row, dibatasi minimal 10 dan maksimal 100 row per job. Untuk total di bawah 10 row, seluruh row diproses dalam satu job. Contoh: 80 row menjadi 8 job berisi 10 row; 1 juta row menjadi 10.000 job berisi 100 row. Progress export dihitung dari jumlah job batch yang selesai.

Job dapat berjalan paralel. Export yang sama tidak dapat overlap untuk actor dan `scopeKey` yang sama. Actor berbeda dapat menjalankan export bersamaan. Aplikasi sebaiknya memberikan `scopeKey` stabil, misalnya ID tenant atau gabungan domain dan tenant.

## Membuat handler export

Implementasikan `ExportHandler` pada aplikasi. Handler bertanggung jawab atas query, key yang dipakai untuk membagi query, header, dan mapping satu record menjadi satu row:

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

Pastikan query memiliki urutan/key yang stabil dan `idColumn()` menunjuk ke kolom unik yang dipakai untuk membagi rentang antar job. Handler sebaiknya eager-load relasi yang dipakai `mapRecord()` untuk menghindari query per row.

## Memulai export dari aplikasi

Controller atau service aplikasi memvalidasi input dan menjalankan otorisasi sebelum memanggil engine:

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

`actor` harus berupa Eloquent model atau `null`. Engine menggunakan actor untuk membatasi pencarian operation dengan `findOwnedOperation()`. `context` adalah data aplikasi yang disimpan bersama operation; shop/tenant scope tetap harus diterapkan oleh handler dan adapter aplikasi.

`startExport()` mengembalikan operation yang baru dibuat. Adapter HTTP biasanya merespons `202 Accepted` dengan `$engine->statusPayload($operation)`.

## Status, polling, dan download

Status payload berisi `id`, `type`, `status`, `processed_rows`, `total_rows`, `processed_jobs`, `total_jobs`, `progress_percentage`, `error_message`, `file_name`, dan `download_url`. Status terminal adalah `completed` atau `failed`; hentikan polling pada salah satunya.

Contoh route status dan download aplikasi:

```php
Route::get('/export-import/{operation}', [ExportController::class, 'status'])
    ->middleware('auth')
    ->name('export-import.status');

Route::get('/export-import/{operation}/download', ExportDownloadController::class)
    ->middleware(['auth', 'signed'])
    ->name('export-import.download');
```

Aktifkan signed download URL dengan konfigurasi berikut:

```php
'download' => [
    'route' => 'export-import.download',
    'parameter' => 'operation',
    'expiration_minutes' => 15,
],
```

Package menyediakan `findOwnedOperation()`, `statusPayload()`, dan `exportDownloadUrl()`, tetapi tidak menyediakan route download umum. Controller download aplikasi harus memeriksa kepemilikan actor dan tenant/account, memastikan operation selesai, lalu mengirim file dari disk privat. Jangan mengandalkan signed URL sebagai pengganti otorisasi aplikasi.

## Integrasi Inertia

Pola adapter Inertia:

1. Endpoint POST memvalidasi format dan filter.
2. Endpoint mengambil actor dan tenant/account aktif dari middleware aplikasi.
3. Endpoint memanggil `startExport()` dengan handler dan filter yang sesuai.
4. Frontend menyimpan `id`, lalu polling endpoint status menggunakan `useHttp`/Inertia.
5. Saat status `completed`, frontend menggunakan `download_url`; saat `failed`, tampilkan `error_message`.

UI Inertia, tombol export, filter, format form, dan polling tidak disediakan oleh package. Implementasikan di aplikasi sesuai desainnya.

Contoh memulai export order dari controller Inertia:

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

Controller mengembalikan `statusPayload($operation)` dengan HTTP 202. Frontend memakai ID operation untuk polling. Adapter harus membatasi request export, status, dan download pada actor yang login serta shop/tenant aktif. `findOwnedOperation()` memeriksa actor; adapter juga harus memeriksa scope di `context` sebelum mengembalikan status atau file.

## Integrasi GraphQL

Mutation GraphQL mengikuti pola yang sama dengan adapter HTTP: validasi/otorisasi actor dan tenant, lalu panggil `startExport()` dan kembalikan `statusPayload()`.

Contoh mutation aplikasi untuk memulai export order:

```graphql
mutation StartOrderExport($format: ExportFormat!, $status: String) {
  exportOrders(format: $format, status: $status) {
    id
    status
    progress_percentage
  }
}
```

Polling operation:

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

`exportOrders` dan query `exportImportOperation` pada contoh adalah schema aplikasi, bukan resolver bawaan package. Pastikan resolver status membatasi operation ke actor yang meminta dan menerapkan shop/tenant scope aplikasi.

## Import handler

`ImportHandler` memperluas `ExportImportHandler`. Engine menyimpan upload ke disk yang dikonfigurasi dan dispatch operation. Handler menerima operation serta `ExportImportProgress`:

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

Contoh tersebut mengasumsikan aplikasi menyediakan `$reader` dan `importCustomer()`; package belum menyediakan parser atau proses simpan domain. `ExportImportProgress::setTotal()` menetapkan total row dan `advance($rows)` menambah jumlah row yang selesai. Handler import saat ini dipanggil sebagai satu queue job, jadi untuk file besar jangan memuat seluruh file ke memory seperti contoh sederhana ini. Aplikasi perlu membaca file secara streaming atau menambahkan strategi chunking import.

## Customization dan batas package

- **Filter dan cakupan data:** ubah query di handler. Untuk tenant/account, taruh ID atau metadata di `context` dan terapkan scope secara eksplisit pada query.
- **Kolom dan format record:** ubah `headers()` dan `mapRecord()` pada `ExportHandler`.
- **Storage dan queue:** override `storage.disk`, `storage.directory`, `queue.connection`, dan `queue.name` di konfigurasi aplikasi.
- **Lifecycle HTTP/GraphQL:** implementasikan adapter, validasi, otorisasi, polling, dan download route di aplikasi. Package tidak bergantung pada Inertia, GraphQL, tenant, atau model user tertentu.
- **Eksklusivitas export:** kirim actor dan `scopeKey` yang sama untuk mencegah export bersamaan pada actor/scope tersebut. Gunakan scope key berbeda jika operasi memang boleh berjalan bersamaan.
- **Format output:** writer bawaan mendukung CSV dan XLSX.
- **Chunk sizing:** saat ini rule 10%, minimum 10 row, maksimum 100 row ditetapkan oleh `ExportChunkSizer`; belum ada key config untuk menggantinya.
- **Writer/finalizer:** strategi penulisan dan penggabungan file dikelola package. Untuk format atau proses yang berbeda, perubahan strategi perlu menjadi extension point package atau implementasi khusus di aplikasi.

### Batas customization

Package sengaja tidak mengikat engine ke model `User`, `Tenant`, Inertia, atau GraphQL. Kustomisasi aplikasi dilakukan dengan:

1. Membuat class handler aplikasi yang mengimplementasikan `ExportHandler` atau `ImportHandler`.
2. Memasukkan filter yang tervalidasi ke `payload` dan scope otorisasi server ke `context` atau kolom khusus aplikasi.
3. Mengatur `scopeKey` stabil untuk cakupan yang tidak boleh memiliki export bersamaan.
4. Membuat adapter untuk start, polling, otorisasi, dan download. Adapter tenant wajib memeriksa tenant pada setiap pembacaan status/file; signed URL hanya membuktikan URL belum diubah dan belum kedaluwarsa.
5. Mempublikasikan config lalu mengganti disk, direktori, nama tabel, atau queue sesuai aplikasi.

Handler dibuat melalui Laravel container (`app($operation->handler)`), sehingga constructor injection untuk service aplikasi dapat dipakai. Model operation default adalah `Agung\LaravelExportImport\ExportImportOperation`. Aplikasi dapat menggantinya dengan model turunan melalui `export-import.models.operation`; engine dan jobs package menggunakan model terkonfigurasi. Untuk mengganti nama tabel saja, gunakan `export-import.tables.operations`.

### Menambahkan kolom khusus aplikasi

Migration package sengaja tidak memiliki kolom tenant. Jika aplikasi membutuhkan `tenant_id` atau metadata khusus lain di tabel `export_import_operations`, tambahkan kolomnya melalui migration aplikasi dan buat model turunan untuk relasi atau cast khusus.

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

Daftarkan model aplikasi pada `config/export-import.php`:

```php
'models' => [
    'operation' => App\Models\ExportImportOperation::class,
],
```

Setelah memanggil engine, hubungkan tenant pada operation dalam transaksi yang sama:

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

Engine dispatch melakukan `afterCommit`, sehingga worker baru menerima job setelah transaksi menyimpan operation beserta kolom aplikasi. Pastikan query handler, status, dan download memakai kolom tersebut untuk menerapkan scope. Dengan pola ini migration package tetap dapat dipakai lintas aplikasi, sementara tenant hanya ada pada schema dan implementasi aplikasi yang memerlukannya.

Class internal yang ditandai `final` memang tidak dapat di-extend. Model operation adalah extension point konfigurasi yang didukung; chunk sizing, writer, dan finalizer belum memiliki konfigurasi untuk diganti oleh aplikasi.

## Worker

Jalankan worker Laravel pada koneksi/queue yang dikonfigurasi. Jika menggunakan default connection dan queue:

```bash
php artisan queue:work
```

Jika package dikonfigurasi ke koneksi atau queue tertentu, jalankan worker dengan nilai yang sama, misalnya:

```bash
php artisan queue:work database --queue=exports
```
