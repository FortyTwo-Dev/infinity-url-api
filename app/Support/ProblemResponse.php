<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProblemResponse
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function make(
        Request $request,
        int $status,
        string $code,
        string $title,
        ?string $detail = null,
        array $errors = [],
    ): JsonResponse {
        $base = rtrim((string) config('identity.problem_base'), '/');

        $payload = [
            'type' => $base.'/'.str_replace('_', '-', $code),
            'title' => $title,
            'status' => $status,
            'instance' => $request->getPathInfo(),
            'code' => $code,
        ];

        if ($detail !== null) {
            $payload['detail'] = $detail;
        }

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status, [
            'Content-Type' => 'application/problem+json',
        ]);
    }
}
