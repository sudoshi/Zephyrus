<?php

use App\Models\User;
use App\Services\DashboardService;

beforeEach(function () {
    $this->service = new DashboardService;
});

describe('getImprovementStats', function () {
    it('returns expected stat keys', function () {
        $stats = $this->service->getImprovementStats();

        expect($stats)->toBeArray()
            ->toHaveKeys(['total', 'activePDSA', 'opportunities', 'libraryItems']);
    });

    it('returns integer values for all stats', function () {
        $stats = $this->service->getImprovementStats();

        expect($stats['total'])->toBeInt();
        expect($stats['activePDSA'])->toBeInt();
        expect($stats['opportunities'])->toBeInt();
        expect($stats['libraryItems'])->toBeInt();
    });
});

describe('getBottleneckStats', function () {
    it('returns stats with expected keys', function () {
        $data = $this->service->getBottleneckStats();

        expect($data)->toHaveKey('stats');
        expect($data['stats'])->toHaveKeys(['active', 'avgResolutionTime', 'patientImpact']);
    });

    it('returns numeric values', function () {
        $data = $this->service->getBottleneckStats();

        expect($data['stats']['active'])->toBeInt();
        expect($data['stats']['avgResolutionTime'])->toBeNumeric();
        expect($data['stats']['patientImpact'])->toBeNumeric();
    });
});

describe('getRootCauses', function () {
    it('returns a non-empty array', function () {
        $causes = $this->service->getRootCauses();

        expect($causes)->toBeArray()->not->toBeEmpty();
    });

    it('returns items with required fields', function () {
        $causes = $this->service->getRootCauses();

        foreach ($causes as $cause) {
            expect($cause)->toHaveKeys(['rank', 'type', 'location', 'impactedPatients', 'score']);
        }
    });

    it('returns items sorted by rank', function () {
        $causes = $this->service->getRootCauses();

        $ranks = array_column($causes, 'rank');
        $sorted = $ranks;
        sort($sorted);

        expect($ranks)->toBe($sorted);
    });

    it('returns items with causes array', function () {
        $causes = $this->service->getRootCauses();

        foreach ($causes as $cause) {
            expect($cause)->toHaveKey('causes');
            expect($cause['causes'])->toBeArray()->not->toBeEmpty();
        }
    });
});

describe('getOpportunities', function () {
    it('returns an array of opportunities', function () {
        $opportunities = $this->service->getOpportunities();

        expect($opportunities)->toBeArray();
    });

    it('returns items with required fields', function () {
        $opportunities = $this->service->getOpportunities();

        foreach ($opportunities as $opportunity) {
            expect($opportunity)->toHaveKeys(['title', 'description', 'department', 'priority', 'status']);
        }
    });
});

describe('getLibraryResources', function () {
    it('returns an array of resources', function () {
        $resources = $this->service->getLibraryResources();

        expect($resources)->toBeArray();
    });

    it('returns items with required fields', function () {
        $resources = $this->service->getLibraryResources();

        foreach ($resources as $resource) {
            expect($resource)->toHaveKeys(['title', 'description', 'category', 'type', 'dateAdded']);
        }
    });
});

describe('getActiveCycles', function () {
    it('returns an array of PDSA cycles', function () {
        $cycles = $this->service->getActiveCycles();

        expect($cycles)->toBeArray();
    });

    it('returns cycles with required fields', function () {
        $cycles = $this->service->getActiveCycles();

        foreach ($cycles as $cycle) {
            expect($cycle)->toHaveKeys([
                'id', 'title', 'objective', 'status', 'currentPhase',
                'startDate', 'targetDate', 'progress',
            ]);
        }
    });

    it('returns cycles with valid progress values', function () {
        $cycles = $this->service->getActiveCycles();

        foreach ($cycles as $cycle) {
            expect($cycle['progress'])->toBeGreaterThanOrEqual(0)
                ->toBeLessThanOrEqual(100);
        }
    });
});

describe('getPdsaCycle', function () {
    it('returns a cycle with the given ID', function () {
        $cycle = $this->service->getPdsaCycle('42');

        expect($cycle)->toBeArray();
        expect($cycle['id'])->toBe('42');
    });

    it('returns cycle with all PDSA phases', function () {
        $cycle = $this->service->getPdsaCycle('1');

        expect($cycle)->toHaveKey('phases');
        expect($cycle['phases'])->toHaveKeys(['plan', 'do', 'study', 'act']);
    });
});

describe('updateWorkflowPreference', function () {
    it('updates user workflow preference', function () {
        $user = Mockery::mock(User::class);
        $user->shouldReceive('update')
            ->once()
            ->with(['workflow_preference' => 'perioperative']);

        $this->service->updateWorkflowPreference($user, 'perioperative');
    });

    it('accepts valid workflow values', function () {
        $workflows = ['superuser', 'rtdc', 'perioperative', 'emergency', 'improvement'];

        foreach ($workflows as $workflow) {
            $user = Mockery::mock(User::class);
            $user->shouldReceive('update')
                ->once()
                ->with(['workflow_preference' => $workflow]);

            $this->service->updateWorkflowPreference($user, $workflow);
        }
    });
});
