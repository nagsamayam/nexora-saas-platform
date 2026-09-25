<?php

declare(strict_types=1);

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
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email', 320);
            $table->string('email_normalized', 320);
            $table->text('password_hash');
            $table->string('status', 32)->default('active');
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
            $table->unsignedBigInteger('auth_version')->default(0);
            $table->unsignedBigInteger('row_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->softDeletesTz();
        });

        DB::statement("
            ALTER TABLE users
            ADD CONSTRAINT users_status_check
            CHECK (status IN ('invited', 'active', 'suspended', 'disabled'));
        ");

        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email_normalized) WHERE deleted_at IS NULL;');
        DB::statement('CREATE INDEX users_status_index ON users (status) WHERE deleted_at IS NULL;');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        DB::statement('DROP INDEX IF EXISTS users_status_index;');
        DB::statement('DROP INDEX IF EXISTS users_email_unique;');
        Schema::dropIfExists('users');
    }
};
