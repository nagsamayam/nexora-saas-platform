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
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 120);
            $table->string('aggregate_type', 120);
            $table->uuid('aggregate_id');
            $table->string('event_key', 255)->nullable();
            $table->jsonb('payload');
            $table->jsonb('headers')->nullable();
            $table->string('status', 32)->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_status_check CHECK (status IN ('pending', 'publishing', 'published', 'failed'));");
        DB::statement('CREATE INDEX outbox_messages_status_available_index ON outbox_messages (status, available_at) WHERE status IN (\'pending\', \'publishing\');');
        DB::statement('CREATE INDEX outbox_messages_aggregate_index ON outbox_messages (aggregate_type, aggregate_id);');
        DB::statement('CREATE UNIQUE INDEX outbox_messages_event_key_unique ON outbox_messages (event_key) WHERE event_key IS NOT NULL;');

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 120);
            $table->uuid('actor_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('CREATE INDEX audit_logs_user_created_index ON audit_logs (user_id, created_at DESC);');
        DB::statement('CREATE INDEX audit_logs_event_type_created_index ON audit_logs (event_type, created_at DESC);');
        DB::statement('CREATE INDEX audit_logs_actor_created_index ON audit_logs (actor_id, created_at DESC);');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('outbox_messages');
    }
};
