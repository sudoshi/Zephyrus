<?php

namespace App\Http\Controllers\Operations;

use App\Data\CaseManagementMockData;
use App\Http\Controllers\Controller;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class CaseManagementController extends Controller
{
    public function index()
    {
        $mockData = CaseManagementMockData::getData();

        return Inertia::render('Operations/CaseManagement', [
            'procedures' => new Collection($mockData['mockProcedures']),
            'specialties' => $mockData['specialties'],
            'locations' => $mockData['locations'],
            'stats' => $mockData['stats'],
        ]);
    }
}
