<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Data\CaseManagementMockData;
use Inertia\Inertia;
use Illuminate\Support\Collection;

class CaseManagementController extends Controller
{
    public function index()
    {
        $mockData = CaseManagementMockData::getData();
        
        return Inertia::render('Operations/CaseManagement', [
            'procedures' => new Collection($mockData['mockProcedures']),
            'specialties' => $mockData['specialties'],
            'locations' => $mockData['locations'],
            'stats' => $mockData['stats']
        ]);
    }
}
