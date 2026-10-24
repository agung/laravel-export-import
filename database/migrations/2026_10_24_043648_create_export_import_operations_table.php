<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $operationsTable = (string) config('export-import.tables.operations');
        $disk = (string) config('export-import.storage.disk', 'local');

        Schema::create($operationsTable, function (Blueprint $table) use ($disk): void {
            $table->id();
            $table->string('type', 20);
            $table->string('status', 20)->default('queued');
            $table->string('handler');
            $table->string('format', 10)->nullable();
            $table->string('disk')->default($disk);
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedBigInteger('total_rows')->nullable();
            $table->text('error_message')->nullable();
            $table->string('active_export_key', 64)->nullable()->unique();
            $table->string('batch_id')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('context')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->timestamps();
            $table->index(['actor_type', 'actor_id']);
            $table->index(['type', 'status']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists((string) config('export-import.tables.operations'));
    }
};
