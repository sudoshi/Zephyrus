<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class DesignController extends Controller
{
    public function components()
    {
        return Inertia::render('Design/Components');
    }

    public function cards()
    {
        return Inertia::render('Design/DesignCardsPage');
    }
}
