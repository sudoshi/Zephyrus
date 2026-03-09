<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'must_change_password' => false,
        'workflow_preference' => 'improvement',
    ]);
});

describe('nursing operations API', function () {
    it('returns nursing operations data for authenticated user', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/improvement/api/nursing-operations?hospital=Virtua+Marlton+Hospital&workflow=Admissions&timeRange=24+Hours');

        $response->assertOk()
            ->assertJsonStructure(['nodes', 'edges', 'metrics']);
    });

    it('returns admissions data by default', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/improvement/api/nursing-operations');

        $response->assertOk()
            ->assertJsonStructure([
                'nodes' => [
                    '*' => ['id', 'position', 'data'],
                ],
                'edges' => [
                    '*' => ['id', 'source', 'target'],
                ],
            ]);
    });

    it('returns discharge workflow data', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/improvement/api/nursing-operations?workflow=Discharges');

        $response->assertOk();

        $data = $response->json();
        $nodeIds = collect($data['nodes'])->pluck('id')->all();

        expect($nodeIds)->toContain('discharge_order');
    });

    it('applies time range multiplier', function () {
        $dayResponse = $this->actingAs($this->user)
            ->getJson('/improvement/api/nursing-operations?timeRange=24+Hours');

        $weekResponse = $this->actingAs($this->user)
            ->getJson('/improvement/api/nursing-operations?timeRange=7+Days');

        $dayNodes = $dayResponse->json('nodes');
        $weekNodes = $weekResponse->json('nodes');

        $dayCount = $dayNodes[0]['data']['metrics']['count'];
        $weekCount = $weekNodes[0]['data']['metrics']['count'];

        expect($weekCount)->toBe($dayCount * 7);
    });
});

describe('process layout API', function () {
    it('saves a process layout', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/improvement/process/layout', [
                'process_type' => 'nursing_operations',
                'hospital' => 'Virtua Marlton Hospital',
                'workflow' => 'Admissions',
                'time_range' => '24 Hours',
                'layout_data' => [
                    'nodes' => [['id' => 'test', 'position' => ['x' => 0, 'y' => 0]]],
                ],
            ]);

        $response->assertNoContent();
    });

    it('retrieves a saved process layout', function () {
        // First save a layout
        $this->actingAs($this->user)
            ->postJson('/improvement/process/layout', [
                'process_type' => 'nursing_operations',
                'hospital' => 'Virtua Marlton Hospital',
                'workflow' => 'Admissions',
                'time_range' => '24 Hours',
                'layout_data' => [
                    'nodes' => [['id' => 'test', 'position' => ['x' => 100, 'y' => 200]]],
                ],
            ]);

        // Then retrieve it
        $response = $this->actingAs($this->user)
            ->getJson('/improvement/process/layout?hospital=Virtua+Marlton+Hospital&workflow=Admissions&time_range=24+Hours');

        $response->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('process_type', 'nursing_operations');
    });

    it('returns not found for non-existent layout', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/improvement/process/layout?hospital=NonExistent&workflow=Admissions&time_range=24+Hours');

        $response->assertOk()
            ->assertJsonPath('found', false);
    });

    it('validates required fields when saving layout', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/improvement/process/layout', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['process_type', 'hospital', 'workflow', 'time_range', 'layout_data']);
    });

    it('validates required fields when getting layout', function () {
        $response = $this->actingAs($this->user)
            ->getJson('/improvement/process/layout');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['hospital', 'workflow', 'time_range']);
    });
});

describe('viewport API', function () {
    it('saves viewport state', function () {
        $response = $this->actingAs($this->user)
            ->postJson('/improvement/process/viewport', [
                'process_type' => 'nursing_operations',
                'hospital' => 'Virtua Marlton Hospital',
                'workflow' => 'Admissions',
                'time_range' => '24 Hours',
                'layout_data' => [
                    'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
                ],
            ]);

        $response->assertNoContent();
    });
});

describe('dashboard endpoints', function () {
    it('loads improvement dashboard', function () {
        $response = $this->actingAs($this->user)
            ->get('/dashboard/improvement');

        $response->assertStatus(200);
    });

    it('loads bottlenecks page', function () {
        $response = $this->actingAs($this->user)
            ->get('/improvement/bottlenecks');

        $response->assertStatus(200);
    });

    it('loads root cause page', function () {
        $response = $this->actingAs($this->user)
            ->get('/improvement/root-cause');

        $response->assertStatus(200);
    });

    it('loads PDSA index page', function () {
        $response = $this->actingAs($this->user)
            ->get('/improvement/pdsa');

        $response->assertStatus(200);
    });

    it('loads PDSA show page', function () {
        $response = $this->actingAs($this->user)
            ->get('/improvement/pdsa/1');

        $response->assertStatus(200);
    });

    it('changes workflow preference', function () {
        $response = $this->actingAs($this->user)
            ->get('/set-preference/perioperative');

        $response->assertRedirect('/dashboard/perioperative');

        $this->user->refresh();
        expect($this->user->workflow_preference)->toBe('perioperative');
    });
});
