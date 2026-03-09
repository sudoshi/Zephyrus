<?php

namespace App\Services;

use App\Models\ProcessLayout;
use Illuminate\Support\Facades\Log;

class ProcessLayoutService
{
    /**
     * Save or update a process layout for the given user.
     */
    public function saveLayout(int $userId, array $validated): void
    {
        Log::info('Saving process layout with data:', [
            'process_type' => $validated['process_type'],
            'hospital' => $validated['hospital'],
            'workflow' => $validated['workflow'],
            'time_range' => $validated['time_range'],
        ]);

        ProcessLayout::updateOrCreate(
            [
                'user_id' => $userId,
                'hospital' => $validated['hospital'],
                'workflow' => $validated['workflow'],
                'time_range' => $validated['time_range'],
            ],
            [
                'process_type' => $validated['process_type'],
                'layout_data' => $validated['layout_data'],
            ]
        );
    }

    /**
     * Get a saved layout for the given user and filter parameters.
     *
     * @return array{layout: array|null, process_type: string|null, found: bool}
     */
    public function getLayout(int $userId, array $validated): array
    {
        Log::info('Getting process layout with params:', [
            'hospital' => $validated['hospital'],
            'workflow' => $validated['workflow'],
            'time_range' => $validated['time_range'],
            'user_id' => $userId,
        ]);

        $layout = ProcessLayout::where('user_id', $userId)
            ->where('hospital', $validated['hospital'])
            ->where('workflow', $validated['workflow'])
            ->where('time_range', $validated['time_range'])
            ->first();

        if ($layout) {
            Log::info('Found saved layout with ID: ' . $layout->id . ' and process_type: ' . $layout->process_type);
        } else {
            Log::info('No saved layout found for the given parameters');
        }

        return [
            'layout' => $layout ? $layout->layout_data : null,
            'process_type' => $layout ? $layout->process_type : null,
            'found' => $layout !== null,
        ];
    }

    /**
     * Save viewport state for a process layout.
     */
    public function saveViewport(int $userId, array $validated): void
    {
        Log::info('Saving viewport with params:', [
            'hospital' => $validated['hospital'],
            'workflow' => $validated['workflow'],
            'time_range' => $validated['time_range'],
            'process_type' => $validated['process_type'],
            'user_id' => $userId,
        ]);

        $layout = ProcessLayout::where('user_id', $userId)
            ->where('hospital', $validated['hospital'])
            ->where('workflow', $validated['workflow'])
            ->where('time_range', $validated['time_range'])
            ->first();

        if ($layout) {
            $layoutData = is_array($layout->layout_data) ? $layout->layout_data : [];
            $layoutData['viewport'] = $validated['layout_data']['viewport'];

            $layout->layout_data = $layoutData;
            $layout->save();

            Log::info('Updated existing layout with viewport data');
        } else {
            ProcessLayout::create([
                'user_id' => $userId,
                'process_type' => $validated['process_type'],
                'hospital' => $validated['hospital'],
                'workflow' => $validated['workflow'],
                'time_range' => $validated['time_range'],
                'layout_data' => $validated['layout_data'],
            ]);

            Log::info('Created new layout with viewport data');
        }
    }
}
