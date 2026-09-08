<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Import\StoreImportRequest;
use App\Http\Requests\Import\UpdateImportRowsRequest;
use App\Http\Resources\ImportBatchResource;
use App\Http\Resources\ImportRowResource;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Services\Import\ImportCommitter;
use App\Services\Import\TemplateGenerator;
use App\Services\Import\WorkbookImporter;
use App\Support\CompanyConfig;
use App\Support\Import\ImportCatalogue;
use App\Support\Import\ImportEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Upload → preview → approve → import. Uploading parses only: no business table
 * is written until `commit` is called on a batch the user has looked at.
 */
class ImportController extends Controller
{
    private const DISK = 'local';

    public function index(): JsonResponse
    {
        $batches = ImportBatch::query()->withCount('rows')->orderByDesc('id')->limit(50)->get();

        return response()->json(['data' => ImportBatchResource::collection($batches)]);
    }

    /**
     * What can be imported, and the exact columns each one expects.
     *
     * Only the entities the user may actually write are listed: the picker is
     * the same permission surface as the modules behind it (rule 6).
     */
    public function entities(Request $request): JsonResponse
    {
        $user = $request->user();

        $entities = array_values(array_map(
            fn (ImportEntity $entity): array => $entity->toArray(),
            array_filter(
                ImportCatalogue::all(),
                fn (ImportEntity $entity): bool => (bool) $user?->can($entity->permission)
                    && app(CompanyConfig::class)->permissionEnabled($entity->permission),
            ),
        ));

        return response()->json(['data' => $entities]);
    }

    /**
     * The blank workbook for an entity, generated from the same definition the
     * importer validates against — so a downloaded template always matches.
     */
    public function template(Request $request, TemplateGenerator $generator): BinaryFileResponse
    {
        $request->validate([
            'entity' => ['required', 'string', Rule::in(ImportCatalogue::keys())],
        ]);

        $entity = ImportCatalogue::find($request->string('entity')->toString());

        abort_unless(app(CompanyConfig::class)->permissionEnabled($entity->permission), 404);
        abort_unless($request->user()?->can($entity->permission), 403);

        return response()
            ->download($generator->generate($entity), $generator->filename($entity))
            ->deleteFileAfterSend();
    }

    /** Parse an uploaded file into a preview. Nothing else happens yet. */
    public function store(StoreImportRequest $request, WorkbookImporter $importer): JsonResponse
    {
        $file = $request->file('file');
        $entity = $request->filled('entity')
            ? ImportCatalogue::find($request->string('entity')->toString())
            : null;

        // Choosing an entity is choosing to write to that module: the entity's
        // own permission is checked on top of `imports.manage`.
        if ($entity !== null) {
            abort_unless(app(CompanyConfig::class)->permissionEnabled($entity->permission), 404);
            abort_unless($request->user()?->can($entity->permission), 403);
        }

        $path = $file->store('imports', self::DISK);

        $batch = ImportBatch::create([
            'original_name' => $file->getClientOriginalName(),
            'entity' => $entity?->key,
            'file_path' => $path,
            'source' => 'upload',
        ]);

        try {
            $batch = $importer->preview($batch, Storage::disk(self::DISK)->path($path), $entity);
        } catch (Throwable $exception) {
            $batch->forceFill(['status' => 'failed', 'error' => $exception->getMessage()])->save();

            return response()->json([
                'message' => 'The workbook could not be read.',
                'errors' => ['file' => [$exception->getMessage()]],
            ], 422);
        }

        activity()->performedOn($batch)->causedBy($request->user())
            ->withProperties(['rows' => $batch->totals['rows'] ?? 0])
            ->log('import.previewed');

        return (new ImportBatchResource($batch))->response()->setStatusCode(201);
    }

    public function show(Request $request, ImportBatch $import): JsonResponse
    {
        $request->validate([
            'sheet' => ['nullable', 'string'],
            'target' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(ImportRow::STATUSES)],
            'issues_only' => ['nullable', 'boolean'],
        ]);

        $query = $import->rows()->orderBy('sheet_name')->orderBy('row_number');

        if ($request->filled('sheet')) {
            $query->where('sheet_name', $request->input('sheet'));
        }
        if ($request->filled('target')) {
            $query->where('target', $request->input('target'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->boolean('issues_only')) {
            $query->whereNotNull('issues')->where('issues', '!=', '[]');
        }

        return response()->json([
            'data' => new ImportBatchResource($import),
            'rows' => ImportRowResource::collection($query->limit(500)->get()),
        ]);
    }

    /** Change which rows will be imported before approving the batch. */
    public function updateRows(UpdateImportRowsRequest $request, ImportBatch $import): JsonResponse
    {
        abort_if($import->status !== 'previewed', 422, 'This batch has already been decided.');

        foreach ($request->validated()['rows'] as $change) {
            $import->rows()
                ->whereKey($change['id'])
                ->where('status', 'pending')
                ->update(['action' => $change['action']]);
        }

        return response()->json(['data' => new ImportBatchResource($import->fresh())]);
    }

    /** The approval step: write the rows the user kept. */
    public function commit(Request $request, ImportBatch $import, ImportCommitter $committer): JsonResponse
    {
        abort_if($import->status !== 'previewed', 422, 'This batch has already been imported or cancelled.');

        $result = $committer->commit($import);

        activity()->performedOn($import)->causedBy($request->user())
            ->withProperties($result)
            ->log('import.committed');

        return response()->json([
            'data' => new ImportBatchResource($import->fresh()),
            'meta' => $result,
        ]);
    }

    public function cancel(Request $request, ImportBatch $import): JsonResponse
    {
        abort_if($import->status !== 'previewed', 422, 'This batch has already been decided.');

        $import->forceFill(['status' => 'cancelled'])->save();
        $import->rows()->where('status', 'pending')->update(['status' => 'skipped']);

        activity()->performedOn($import)->causedBy($request->user())->log('import.cancelled');

        return response()->json(['data' => new ImportBatchResource($import->fresh())]);
    }

    public function destroy(Request $request, ImportBatch $import): JsonResponse
    {
        // The uploaded workbook goes with the batch; imported records stay.
        Storage::disk(self::DISK)->delete($import->file_path);
        $import->delete();

        activity()->causedBy($request->user())->log('import.deleted');

        return response()->json(['message' => 'Import batch deleted.']);
    }
}
