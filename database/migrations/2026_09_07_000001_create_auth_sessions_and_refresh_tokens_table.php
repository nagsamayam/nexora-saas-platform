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
        Schema::create('auth_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 32)->default('active');
            $table->string('device_name', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("
            ALTER TABLE auth_sessions
            ADD CONSTRAINT auth_sessions_status_check
            CHECK (status IN ('active', 'revoked', 'expired'));
        ");

        DB::statement('CREATE INDEX auth_sessions_user_status_index ON auth_sessions (user_id, status);');

        Schema::create('auth_refresh_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('auth_sessions')->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->string('status', 32)->default('active');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('replaced_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("
            ALTER TABLE auth_refresh_tokens
            ADD CONSTRAINT auth_refresh_tokens_status_check
            CHECK (status IN ('active', 'consumed', 'revoked', 'expired'));
        ");

        DB::statement('CREATE UNIQUE INDEX auth_refresh_tokens_token_hash_unique ON auth_refresh_tokens (token_hash);');
        DB::statement('CREATE INDEX auth_refresh_tokens_session_status_index ON auth_refresh_tokens (session_id, status);');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS auth_refresh_tokens_session_status_index;');
        DB::statement('DROP INDEX IF EXISTS auth_refresh_tokens_token_hash_unique;');
        Schema::dropIfExists('auth_refresh_tokens');

        DB::statement('DROP INDEX IF EXISTS auth_sessions_user_status_index;');
        Schema::dropIfExists('auth_sessions');
    }
};
