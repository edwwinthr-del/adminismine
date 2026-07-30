<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customs\StoreCustomsDocumentRequest;
use App\Http\Requests\Customs\UpdateCustomsDocumentRequest;
use App\Http\Resources\CustomsDocumentResource;
use App\Models\CustomsDocument;
use App\Services\FileAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class CustomsDocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CustomsDocument::query()
            ->with(['machine', 'customsCompany', 'payableInvoice'])
            ->withCount('attachments');

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->input('document_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->boolean('open_only')) {
            $query->open();
        }
        if ($request->boolean('missing_paperwork')) {
            $query->missingPaperwork();
        }
        if ($request->filled('machine_id')) {
            $query->forMachine($request->integer('machine_id'));
        }
        if ($request->filled('payable_invoice_id')) {
            $query->where('payable_invoice_id', $request->integer('payable_invoice_id'));
        }
        if ($request->filled('customs_company_id')) {
            $query->where('customs_company_id', $request->integer('customs_company_id'));
        }
        if ($request->filled('cmr_date')) {
            $query->whereDate('cmr_date', $request->date('cmr_date'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('shipment_date', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('shipment_date', '<=', $request->date('date_to'));
        }

        // One search box across every identifier a clerk might have to hand:
        // document/CMR number, carrier, sender, receiver, plate, goods, customs
        // company and machine — the column list lives on the model.
        $query->search($request->input('search'));

        $query->orderByDesc('shipment_date')->orderByDesc('id');

        return CustomsDocumentResource::collection($query->paginate($request->integer('per_page', 100)));
    }

    public function store(StoreCustomsDocumentRequest $request): JsonResponse
    {
        $document = CustomsDocument::create($request->validated());

        activity()->performedOn($document)->causedBy($request->user())->log('customs_document.created');

        return (new CustomsDocumentResource($document->load(['machine', 'customsCompany', 'payableInvoice'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(CustomsDocument $customsDocument): CustomsDocumentResource
    {
        return new CustomsDocumentResource($customsDocument->load([
            'machine', 'customsCompany', 'payableInvoice', 'receivableInvoice',
            'client', 'supplier', 'productionRecord', 'attachments',
        ]));
    }

    public function update(UpdateCustomsDocumentRequest $request, CustomsDocument $customsDocument): CustomsDocumentResource
    {
        $before = $customsDocument->only(['status']);

        $customsDocument->update($request->validated());

        activity()->performedOn($customsDocument)->causedBy($request->user())
            ->withProperties(['before' => $before, 'after' => $customsDocument->only(['status'])])
            ->log('customs_document.updated');

        return new CustomsDocumentResource(
            $customsDocument->load(['machine', 'customsCompany', 'payableInvoice'])
        );
    }

    public function destroy(Request $request, CustomsDocument $customsDocument, FileAttachmentService $files): JsonResponse
    {
        DB::transaction(function () use ($customsDocument, $files) {
            $files->deleteAllFor($customsDocument);
            $customsDocument->delete();
        });

        activity()->performedOn($customsDocument)->causedBy($request->user())->log('customs_document.deleted');

        return response()->json(['message' => 'Customs document deleted.']);
    }

    /**
     * The document register: what is still open, what has no scan on file, and
     * the split per type and status — the basis for the "missing documents" alerts.
     */
    public function register(Request $request): JsonResponse
    {
        $documents = CustomsDocument::query()->withCount('attachments')->get();

        $byStatus = collect(CustomsDocument::STATUSES)
            ->mapWithKeys(fn (string $status): array => [
                $status => $documents->where('status', $status)->count(),
            ]);

        $withoutScan = $documents->where('attachments_count', 0);

        return response()->json([
            'data' => [
                'total' => $documents->count(),
                'by_status' => $byStatus,
                'by_type' => $documents->groupBy('document_type')
                    ->map(fn ($rows, $type): array => [
                        'document_type' => $type,
                        'count' => $rows->count(),
                    ])->sortByDesc('count')->values(),
                'open' => $documents->whereIn('status', CustomsDocument::OPEN_STATUSES)->count(),
                'missing' => $documents->where('status', 'missing')->count(),
                'without_scan' => $withoutScan->count(),
                'unchecked' => $documents->whereIn('status', ['draft', 'received'])->count(),
                'linked_to_machine' => $documents->whereNotNull('machine_id')->count(),
                // Papers that still need attention, newest shipment first.
                'attention' => $documents
                    ->filter(fn (CustomsDocument $document): bool => $document->status === 'missing'
                        || $document->attachments_count === 0)
                    ->sortByDesc(fn (CustomsDocument $document) => $document->shipment_date?->toDateString() ?? '')
                    ->take(20)
                    ->map(fn (CustomsDocument $document): array => [
                        'id' => $document->id,
                        'document_type' => $document->document_type,
                        'document_number' => $document->document_number,
                        'cmr_number' => $document->cmr_number,
                        'shipment_date' => $document->shipment_date?->toDateString(),
                        'status' => $document->status,
                        'has_scan' => $document->attachments_count > 0,
                    ])->values(),
            ],
        ]);
    }
}
