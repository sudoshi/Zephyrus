<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Traits\SafeMigration;

return new class extends Migration
{
    use SafeMigration;
    /**
     * Run the migrations.
     */
public function up(): void
{
    // Check if the table exists
    if (!Schema::hasTable('prod.users')) {
        Schema::create('prod.users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
            $table->string('username')->unique();
        });
    }

        if (!Schema::hasTable('prod.password_reset_tokens')) {
        Schema::create('prod.password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
            });
    }

        if (!Schema::hasTable('prod.sessions')) {
        Schema::create('prod.sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
            });
    }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only drop tables in local environment
        $this->safeDropIfExists('prod.users');
        $this->safeDropIfExists('prod.password_reset_tokens');
        $this->safeDropIfExists('prod.sessions');
    }
};
