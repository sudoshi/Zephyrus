<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(): Response
    {
        return $this->renderSection('hub');
    }

    public function live(): Response
    {
        return $this->renderSection('live');
    }

    public function retrospective(): Response
    {
        return $this->renderSection('retrospective');
    }

    public function predictive(): Response
    {
        return $this->renderSection('predictive');
    }

    public function processIntelligence(): Response
    {
        return $this->renderSection('process-intelligence');
    }

    public function opportunities(): Response
    {
        return $this->renderSection('opportunities');
    }

    public function workbench(): Response
    {
        return $this->renderSection('workbench');
    }

    public function dataQuality(): Response
    {
        return $this->renderSection('data-quality');
    }

    private function renderSection(string $section): Response
    {
        return Inertia::render('Analytics', [
            'workflow' => 'analytics',
            'section' => $section,
        ]);
    }
}
