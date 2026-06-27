<?php

use App\Traits\SafeMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use SafeMigration;

    /**
     * APNs/FCM device registry for the Hummingbird mobile companion. One row per
     * installed app instance, keyed by push token. The notification router fans
     * push to a user's active (non-revoked) devices. No PHI is stored here.
     */
    public function up(): void
    {
        if (! Schema::hasTable('prod.mobile_devices')) {
            Schema::create('prod.mobile_devices', function (Blueprint $table) {
                $table->id('mobile_device_id');
                $table->uuid('device_uuid')->unique();
                $table->unsignedBigInteger('user_id');
                $table->string('platform', 10);                       // ios | android
                $table->string('push_token', 512)->unique();          // APNs/FCM token
                $table->string('app_version', 40)->nullable();
                $table->string('os_version', 40)->nullable();
                $table->string('device_name', 120)->nullable();
                $table->string('locale', 20)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index('user_id');
                $table->index(['user_id', 'revoked_at'], 'idx_mobile_devices_active');
                $table->index('platform');
            });

            DB::statement("ALTER TABLE prod.mobile_devices ADD CONSTRAINT chk_mobile_device_platform CHECK (platform IN ('ios','android'))");
        }
    }

    public function down(): void
    {
        $this->safeDropIfExists('prod.mobile_devices');
    }
};
