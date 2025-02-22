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
        if (!Schema::hasColumn('prod.users', 'username')) {
            Schema::table('prod.users', function (Blueprint $table) {
                $table->string('username')->nullable()->unique()->after('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->isLocalEnvironment()) {
            Schema::table('prod.users', function (Blueprint $table) {
                $table->dropColumn('username');
            });
        }
    }
};
