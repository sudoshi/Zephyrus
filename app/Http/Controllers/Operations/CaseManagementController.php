<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\CaseManagementService;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class CaseManagementController extends Controller
{
    public function index(CaseManagementService $caseManagement)
    {
        $data = $caseManagement->getData();

        return Inertia::render('Operations/CaseManagement', [
            'procedures' => new Collection($data['mockProcedures']),
            'specialties' => $data['specialties'],
            'locations' => $data['locations'],
            'stats' => $data['stats'],
        ]);
    }
}
