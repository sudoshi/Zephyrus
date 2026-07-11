<?php

namespace Tests\Feature\Arena;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OcelProjectQuantityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ocel_project_command_populates_quantities(): void
    {
        DB::table('ocel.objects')->insert([['id' => 'Unit:ICU', 'type' => 'Unit', 'attrs' => '{}', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('ocel.events')->insert([
            ['id' => 'q-admit', 'activity' => 'admit', 'event_time' => now()->subHours(3)->toIso8601String(), 'attrs' => '{}', 'source_system' => 'test', 'source_ref' => 'qa'],
        ]);
        DB::table('ocel.event_object')->insert([['event_id' => 'q-admit', 'object_id' => 'Unit:ICU', 'qualifier' => 'location']]);

        $this->artisan('ocel:project', ['--quantities-only' => true])->assertSuccessful();

        $this->assertDatabaseHas('ocel.quantity_operations', ['object_id' => 'Unit:ICU', 'delta' => 1]);
    }
}
