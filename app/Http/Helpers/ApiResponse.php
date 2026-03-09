<?php

namespace App\Http\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success($data = null, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data], $status);
    }

    public static function error(string $message, int $status = 400, $errors = null): JsonResponse
    {
        $response = ['message' => $message];
        if ($errors) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }
}
