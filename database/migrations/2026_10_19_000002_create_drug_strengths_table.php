<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Дозировки препарата: назначение ссылается на конкретную дозировку.
        Schema::create('drug_strengths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drug_id')->constrained();
            $table->decimal('mg', 8, 2);
            // Есть риска: таблетку можно делить пополам.
            $table->boolean('divisible');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['drug_id', 'mg']);
        });

        DB::statement('ALTER TABLE drug_strengths ADD CONSTRAINT drug_strengths_mg_check CHECK (mg > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drug_strengths');
    }
};
