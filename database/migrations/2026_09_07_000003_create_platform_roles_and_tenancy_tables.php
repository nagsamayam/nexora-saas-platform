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
        Schema::create('platform_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 50)->unique();
            $table->string('description', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('user_platform_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('platform_role_id')->constrained('platform_roles')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['user_id', 'platform_role_id']);
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('slug', 100);
            $table->string('status', 32)->default('pending');
            $table->unsignedBigInteger('row_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
        });

        DB::statement("
            ALTER TABLE tenants
            ADD CONSTRAINT tenants_status_check
            CHECK (status IN ('pending', 'provisioning', 'active', 'suspended', 'disabled'))
        ");

        DB::statement('
            CREATE UNIQUE INDEX tenants_slug_unique
            ON tenants (slug)
            WHERE deleted_at IS NULL
        ');

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32)->default('member');
            $table->string('status', 32)->default('active');
            $table->unsignedBigInteger('row_version')->default(1);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
        });

        DB::statement("
            ALTER TABLE tenant_memberships
            ADD CONSTRAINT tenant_memberships_role_check
            CHECK (role IN ('owner', 'admin', 'member', 'viewer'))
        ");

        DB::statement("
            ALTER TABLE tenant_memberships
            ADD CONSTRAINT tenant_memberships_status_check
            CHECK (status IN ('invited', 'active', 'suspended'))
        ");

        DB::statement('
            CREATE UNIQUE INDEX tenant_memberships_user_tenant_unique
            ON tenant_memberships (tenant_id, user_id)
            WHERE deleted_at IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('user_platform_roles');
        Schema::dropIfExists('platform_roles');
    }
};
