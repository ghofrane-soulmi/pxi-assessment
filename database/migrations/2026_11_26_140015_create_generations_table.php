<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Our UUID doubles as the Idempotency-Key and metadata.generation_id sent to Pixelforge.
            $table->uuid('id')->primary();
            // Money records outlive accounts: a deleted user's generations stay for reporting.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status')->default('pending')->index();

            // What the user asked for
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // Money: integer nano-dollars (1e-9 USD). Never floats.
            $table->bigInteger('min_charge_nanos');           // estimate shown before submitting
            $table->bigInteger('max_charge_nanos');
            $table->json('rates')->nullable();                // fixed by Pixelforge at creation
            $table->integer('input_text_tokens')->nullable(); // usage, known after success
            $table->integer('input_image_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('reasoning_tokens')->nullable();
            $table->bigInteger('provider_cost_nanos')->nullable(); // what Pixelforge charged us
            $table->bigInteger('charge_nanos')->nullable();        // what we charged the user

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->string('image_path')->nullable();         // our stored copy of the PNG
            $table->timestamp('completed_at')->nullable();    // Pixelforge bills on this UTC day
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
