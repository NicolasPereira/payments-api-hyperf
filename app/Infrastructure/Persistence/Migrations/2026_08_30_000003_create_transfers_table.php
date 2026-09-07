<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->decimal('value', 15, 2)->comment('exact positive BRL with 2 decimals');
            $table->unsignedBigInteger('payer_id')->comment('must reference common user');
            $table->unsignedBigInteger('payee_id')->comment('must differ from payer');
            $table->enum('status', ['pending', 'authorized', 'completed', 'failed'])->default('pending');
            $table->string('idempotency_key', 64)->nullable()->comment('hash payer+payee+value, Redis window 3m');
            $table->text('authorization_result')->nullable()->comment('sanitized external result');
            $table->string('correlation_id', 36)->nullable()->comment('trace/correlation value');
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->datetimes();

            $table->foreign('payer_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('payee_id')->references('id')->on('users')->onDelete('restrict');
            $table->index(['payer_id', 'payee_id']);
            $table->index('status');
            $table->index('correlation_id');
            $table->index('idempotency_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
