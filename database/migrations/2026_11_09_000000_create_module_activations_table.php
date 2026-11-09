<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('module_activations', function (Blueprint $table): void {
            $table->string('module')->primary();
            $table->boolean('enabled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_activations');
    }
};
