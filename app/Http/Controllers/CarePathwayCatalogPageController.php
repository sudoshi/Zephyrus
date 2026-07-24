<?php

namespace App\Http\Controllers;

use App\Services\CarePathways\CatalogGovernanceReadService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CarePathwayCatalogPageController extends Controller
{
    public function __construct(
        private readonly CatalogGovernanceReadService $catalog,
    ) {}

    public function index(): Response
    {
        $summary = $this->catalog->summary();
        if ($summary === null) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('CarePathways/Catalog/Index', [
            'initialSummary' => $summary,
            'initialPathways' => $this->catalog->pathways(['page' => 1, 'per_page' => 25]),
        ]);
    }

    public function show(string $versionUuid): Response
    {
        $version = $this->catalog->version($versionUuid);
        if ($version === null) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('CarePathways/Catalog/Show', [
            'initialVersion' => $version,
        ]);
    }
}
