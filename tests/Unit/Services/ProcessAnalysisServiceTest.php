<?php

use App\Services\ProcessAnalysisService;

beforeEach(function () {
    $this->service = new ProcessAnalysisService;
});

describe('getNursingOperations', function () {
    it('returns admissions workflow data by default', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        expect($data)->toBeArray()
            ->toHaveKeys(['nodes', 'edges', 'metrics']);
    });

    it('returns discharge workflow data when requested', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Discharges', '24 Hours');

        expect($data)->toBeArray()
            ->toHaveKeys(['nodes', 'edges', 'metrics']);
    });

    it('returns nodes with expected structure', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        expect($data['nodes'])->toBeArray()->not->toBeEmpty();

        $firstNode = $data['nodes'][0];
        expect($firstNode)->toHaveKeys(['id', 'position', 'data']);
        expect($firstNode['data'])->toHaveKey('label');
        expect($firstNode['data'])->toHaveKey('metrics');
    });

    it('returns edges with expected structure', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        expect($data['edges'])->toBeArray()->not->toBeEmpty();

        $firstEdge = $data['edges'][0];
        expect($firstEdge)->toHaveKeys(['id', 'source', 'target']);
    });

    it('returns metrics with staffing data', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        expect($data['metrics'])->toHaveKey('staffing');
        expect($data['metrics']['staffing'])->toHaveKeys(['nurses', 'physicians']);
        expect($data['metrics']['staffing']['nurses'])->toHaveKeys(['assigned', 'required']);
    });

    it('returns metrics with space data', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        expect($data['metrics']['space'])->toHaveKey('rooms');
        expect($data['metrics']['space']['rooms'])->toHaveKeys(['occupied', 'capacity']);
    });

    it('returns metrics with cascade analysis', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        expect($data['metrics'])->toHaveKey('cascade');
        expect($data['metrics']['cascade'])->toHaveKeys(['primaryProcess', 'affectedProcesses']);
    });

    it('returns metrics with wait time data', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        expect($data['metrics'])->toHaveKey('waitTime');
        expect($data['metrics']['waitTime'])->toHaveKeys(['current', 'benchmark', 'peakMultipliers']);
    });

    it('returns metrics with predictions', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');

        expect($data['metrics'])->toHaveKey('predictions');
        expect($data['metrics']['predictions'])->toHaveKeys([
            'resourceUtilization',
            'patternAnalysis',
            'correlations',
            'optimizationSuggestions',
        ]);
    });
});

describe('time range multiplier', function () {
    it('applies 7-day multiplier to node counts', function () {
        $dayData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');
        $weekData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '7 Days');

        $dayCount = $dayData['nodes'][0]['data']['metrics']['count'];
        $weekCount = $weekData['nodes'][0]['data']['metrics']['count'];

        expect($weekCount)->toBe($dayCount * 7);
    });

    it('applies 14-day multiplier to node counts', function () {
        $dayData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');
        $twoWeekData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '14 Days');

        $dayCount = $dayData['nodes'][0]['data']['metrics']['count'];
        $twoWeekCount = $twoWeekData['nodes'][0]['data']['metrics']['count'];

        expect($twoWeekCount)->toBe($dayCount * 14);
    });

    it('applies 30-day multiplier to node counts', function () {
        $dayData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');
        $monthData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '1 Month');

        $dayCount = $dayData['nodes'][0]['data']['metrics']['count'];
        $monthCount = $monthData['nodes'][0]['data']['metrics']['count'];

        expect($monthCount)->toBe($dayCount * 30);
    });

    it('applies multiplier to edge patient counts', function () {
        $dayData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '24 Hours');
        $weekData = $this->service->getNursingOperations('Summit Regional Medical Center', 'Admissions', '7 Days');

        $dayEdge = collect($dayData['edges'])->firstWhere('id', 'e1');
        $weekEdge = collect($weekData['edges'])->firstWhere('id', 'e1');

        expect($weekEdge['data']['patientCount'])
            ->toBe($dayEdge['data']['patientCount'] * 7);
    });
});

describe('discharge workflow', function () {
    it('includes clinical branch nodes', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Discharges', '24 Hours');

        $nodeIds = collect($data['nodes'])->pluck('id')->all();

        expect($nodeIds)->toContain('discharge_order')
            ->toContain('med_reconciliation')
            ->toContain('discharge_summary')
            ->toContain('patient_education');
    });

    it('includes pharmacy branch nodes', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Discharges', '24 Hours');

        $nodeIds = collect($data['nodes'])->pluck('id')->all();

        expect($nodeIds)->toContain('med_review')
            ->toContain('rx_processing')
            ->toContain('take_home_meds');
    });

    it('includes final departure step', function () {
        $data = $this->service->getNursingOperations('Summit Regional Medical Center', 'Discharges', '24 Hours');

        $nodeIds = collect($data['nodes'])->pluck('id')->all();

        expect($nodeIds)->toContain('departure');
    });
});
