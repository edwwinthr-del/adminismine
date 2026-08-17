<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Reports\Report;
use App\Reports\ReportRegistry;
use App\Reports\StatementReport;
use App\Services\Reports\ReportExporter;
use App\Support\MonthPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Runs and exports any registered report. A user only ever sees the reports
 * their permissions already allow elsewhere in the app.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportRegistry $registry) {}

    /** The report catalogue, filtered to what this user may run. */
    public function index(Request $request): JsonResponse
    {
        $reports = collect($this->registry->availableTo($request->user()))
            ->map(fn ($report): array => [
                'key' => $report->key(),
                'filters' => $report->filters(),
                'columns' => $report->columns(),
                'needs_signature' => $report->needsSignature(),
                // Statements need a party chosen before they mean anything.
                'parties' => $report instanceof StatementReport ? $report->parties() : null,
            ])
            ->values();

        return response()->json(['data' => $reports]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $report = $this->resolve($request, $key);
        $filters = $this->filters($request, $report->filters());
        $rows = $report->rows($filters);

        return response()->json([
            'data' => [
                'key' => $report->key(),
                'columns' => $report->columns(),
                'rows' => $rows,
                'totals' => $report->totals($rows),
                'filters' => $filters,
                'row_count' => count($rows),
            ],
        ]);
    }

    /** The spec's requirement: the user picks PDF or Excel at export time. */
    public function export(Request $request, string $key, ReportExporter $exporter): BinaryFileResponse
    {
        $request->validate(['format' => ['required', 'in:xlsx,pdf'], 'title' => ['nullable', 'string', 'max:120']]);

        $report = $this->resolve($request, $key);
        $filters = $this->filters($request, $report->filters());
        $rows = $report->rows($filters);

        // The title comes from the frontend so the export is in the user's own
        // language — nothing here stores or guesses translated text.
        $title = $request->input('title', $report->key());

        $path = $request->input('format') === 'pdf'
            ? $exporter->pdf($report, $rows, $filters, $request->user(), $title)
            : $exporter->excel($report, $rows, $filters, $request->user(), $title);

        activity()->causedBy($request->user())
            ->withProperties(['report' => $report->key(), 'format' => $request->input('format'), 'rows' => count($rows)])
            ->log('report.exported');

        $extension = $request->input('format') === 'pdf' ? 'pdf' : 'xlsx';
        $filename = Str::slug($title).'-'.now()->format('Ymd').'.'.$extension;

        return response()->download($path, $filename)->deleteFileAfterSend();
    }

    private function resolve(Request $request, string $key): Report
    {
        $report = $this->registry->find($key);

        abort_if($report === null, 404, 'Unknown report.');
        abort_unless($request->user()->can($report->permission()), 403);

        return $report;
    }

    /**
     * @param  list<string>  $accepted
     * @return array<string, mixed>
     */
    private function filters(Request $request, array $accepted): array
    {
        $rules = [
            'month' => ['nullable', 'string', MonthPeriod::rule()],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'supplier_id' => ['nullable', 'integer', 'exists:dobavljaci,id'],
            'client_id' => ['nullable', 'integer', 'exists:klijenti,id'],
            'employee_id' => ['nullable', 'integer', 'exists:radnici,id'],
            'worksite_id' => ['nullable', 'integer', 'exists:gradilista,id'],
            'mine_id' => ['nullable', 'integer', 'exists:rudnici,id'],
            'project_id' => ['nullable', 'integer', 'exists:projekti,id'],
        ];

        $request->validate(array_intersect_key($rules, array_flip($accepted)));

        return array_filter(
            $request->only($accepted),
            fn ($value): bool => $value !== null && $value !== '',
        );
    }
}
