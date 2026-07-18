<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-07 HFE audit Phase 1 — alert acknowledgement + ownership. An
 * acknowledged alert stays visible (suppression would lie) but reads as
 * "owned": who saw it and when. Escalation (warn→crit) clears the ack so a
 * worsening condition re-alarms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prod.cockpit_alerts', function (Blueprint $table) {
            $table->timestampTz('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            // Denormalized display name so the snapshot payload never joins users.
            $table->string('acknowledged_by_name', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prod.cockpit_alerts', function (Blueprint $table) {
            $table->dropColumn(['acknowledged_at', 'acknowledged_by', 'acknowledged_by_name']);
        });
    }
};
