<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The demo schema. It is not part of the package — nothing under `workbench/`
 * ships (see `.gitattributes`); this exists so `testbench serve` has rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('city');
        });

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('status');
            $table->decimal('salary', 12, 2);
            $table->unsignedTinyInteger('workload');
            $table->timestamp('hired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
        Schema::dropIfExists('companies');
    }
};
