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
        Schema::create('notification_outbox', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('transfer_id')->unique()->comment('one notification intent per transfer');
            $table->unsignedBigInteger('payee_id')->comment('recipient of the notification');
            $table->json('payload')->comment('sanitized JSON for notifier');
            $table->enum('status', ['pending', 'processing', 'sent', 'failed'])->default('pending');
            $table->unsignedInteger('attempts')->default(0)->comment('max 3 delivery attempts');
            $table->timestamp('available_at')->useCurrent()->comment('next eligible processing time');
            $table->timestamp('lease_until')->nullable()->comment('prevents stuck processing ownership');
            $table->text('last_response')->nullable()->comment('sanitized, no secrets');
            $table->timestamp('sent_at')->nullable();
            $table->datetimes();

            $table->foreign('transfer_id')->references('id')->on('transfers')->onDelete('cascade');
            $table->foreign('payee_id')->references('id')->on('users')->onDelete('restrict');
            $table->index(['status', 'available_at']);
            $table->index('lease_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
    }
};
