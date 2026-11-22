<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Abuse\CreateReport;
use App\Http\Requests\Reports\StoreReportRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * The only public endpoint that writes without authentication.
 *
 * Always 202, whether the slug exists or not (docs/04 §4.1 bis). The answer
 * reveals nothing about the link; a 404 here would turn the form into a
 * mapping tool.
 */
final class ReportController extends Controller
{
    #[OpenApiResponse(
        status: 202,
        type: 'array{reference: string, status: string}',
        description: 'Always accepted, whether the link exists or not.',
    )]
    public function __invoke(StoreReportRequest $request, CreateReport $action): JsonResponse
    {
        $data = $request->validated();

        // Honeypot: answer, write nothing, and give a reference that exists
        // nowhere. A robot learns nothing.
        if ($this->isHoneypot($data)) {
            return $this->accepted((string) Str::ulid());
        }

        $report = $action->handle([
            'slug' => (string) $data['slug'],
            'category' => (string) $data['category'],
            'details' => isset($data['details']) ? (string) $data['details'] : null,
            'email' => isset($data['email']) ? (string) $data['email'] : null,
        ]);

        return $this->accepted($report->public_id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isHoneypot(array $data): bool
    {
        return isset($data['website']) && trim((string) $data['website']) !== '';
    }

    private function accepted(string $reference): JsonResponse
    {
        return response()->json([
            'reference' => $reference,
            'status' => 'received',
        ], 202);
    }
}
