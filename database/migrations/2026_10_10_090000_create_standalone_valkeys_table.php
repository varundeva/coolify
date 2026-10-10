<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('standalone_valkeys', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('description')->nullable();

            $table->text('valkey_password');
            $table->longText('valkey_conf')->nullable();

            $table->boolean('is_log_drain_enabled')->default(false);
            $table->boolean('is_include_timestamps')->default(false);
            $table->softDeletes();

            $table->string('status')->default('exited');

            $table->string('image')->default('valkey/valkey:9.0-alpine');

            $table->boolean('is_public')->default(false);
            $table->integer('public_port')->nullable();
            $table->text('ports_mappings')->nullable();

            $table->string('limits_memory')->default('0');
            $table->string('limits_memory_swap')->default('0');
            $table->integer('limits_memory_swappiness')->default(60);
            $table->string('limits_memory_reservation')->default('0');

            $table->string('limits_cpus')->default('0');
            $table->string('limits_cpuset')->nullable()->default(null);
            $table->integer('limits_cpu_shares')->default(1024);

            $table->timestamp('started_at')->nullable();
            $table->morphs('destination');
            $table->foreignId('environment_id')->nullable();

            // Resource limits
            $table->integer('restart_count')->default(0);
            $table->timestamp('last_restart_at')->nullable();
            $table->string('last_restart_type')->nullable();
            $table->timestamp('last_online_at')->nullable();

            // Public port proxy
            $table->integer('public_port_timeout')->nullable()->default(3600);

            // SSL
            $table->boolean('enable_ssl')->default(false);

            // Configuration hash for change detection
            $table->string('config_hash')->nullable();

            // Custom docker run options
            $table->text('custom_docker_run_options')->nullable();

            // Health check
            $table->boolean('health_check_enabled')->default(true);
            $table->integer('health_check_interval')->default(5);
            $table->integer('health_check_timeout')->default(5);
            $table->integer('health_check_retries')->default(3);
            $table->integer('health_check_start_period')->default(1);

            // Legacy password quoting (for safe migration from pre-v4.3.23 behaviour)
            $table->boolean('legacy_password_quoting')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('standalone_valkeys');
    }
};
