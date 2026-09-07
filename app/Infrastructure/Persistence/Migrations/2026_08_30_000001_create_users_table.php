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
        Schema::create('users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('full_name');
            $table->enum('document_type', ['cpf', 'cnpj'])->comment('cpf for common, cnpj for merchant');
            $table->string('document', 14)->unique()->comment('normalized uppercase, CPF ^[0-9]{11}$ or CNPJ ^[A-Z0-9]{12}[0-9]{2}$');
            $table->string('email', 255)->unique()->comment('case-insensitive unique, normalized lowercase');
            $table->string('password_hash', 255)->comment('ARGON2ID/BCRYPT hash only');
            $table->enum('type', ['common', 'merchant'])->comment('merchant can only receive');
            $table->datetimes();
            $table->index(['document_type', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
