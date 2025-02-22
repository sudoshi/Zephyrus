<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RTDCController extends Controller
{
    /**
     * Update the Red Stretch Plan for a unit
     */
    public function updateRedStretchPlan(Request $request)
    {
        $validated = $request->validate([
            'unitId' => 'required|integer',
            'plan' => 'required|string|max:500'
        ]);

        try {
            // In a real app, this would update the database
            // For now, we'll just log it
            Log::info('Updating Red Stretch Plan', [
                'unitId' => $validated['unitId'],
                'plan' => $validated['plan']
            ]);

            return response()->json([
                'message' => 'Red Stretch Plan updated successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating Red Stretch Plan', [
                'error' => $e->getMessage(),
                'unitId' => $validated['unitId']
            ]);

            return response()->json([
                'message' => 'Failed to update Red Stretch Plan'
            ], 500);
        }
    }
}
