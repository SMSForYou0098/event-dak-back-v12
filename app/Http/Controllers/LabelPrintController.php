<?php

namespace App\Http\Controllers;

use App\Models\LabelPrint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LabelPrintController extends Controller
{
    /**
     * Display a listing of label prints.
     */
    public function index(Request $request)
    {

        $query = LabelPrint::query();

        if ($request->has('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $labelPrints = $query->select(
            'batch_id',
            'user_id',
            DB::raw('MAX(created_at) as created_at'),
            DB::raw('COUNT(*) as total_records'),
            DB::raw('SUM(CASE WHEN status = true THEN 1 ELSE 0 END) as printed_records'),
            DB::raw('SUM(CASE WHEN status = false THEN 1 ELSE 0 END) as pending_records')
        )
            ->groupBy('batch_id', 'user_id')
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'status' => true,
            'data' => $labelPrints->items(),
            'pagination' => [
                'current_page' => $labelPrints->currentPage(),
                'per_page' => $labelPrints->perPage(),
                'total' => $labelPrints->total(),
                'last_page' => $labelPrints->lastPage(),
            ],
        ]);
    }

    /**
     * Store bulk label prints with auto-generated batch_id.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'labels' => 'required|array|min:1',
            'labels.*.name' => 'required|string|max:255',
            'labels.*.surname' => 'required|string|max:255',
            'labels.*.number' => 'nullable|string|max:50',
            'labels.*.designation' => 'nullable|string|max:255',
            'labels.*.company_name' => 'nullable|string|max:255',
            'labels.*.stall_number' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $batchId = Str::uuid()->toString();
        $userId = $request->user_id;

        try {
            DB::beginTransaction();

            $labelPrints = [];
            $now = now();

            foreach ($request->labels as $labelData) {
                $labelPrints[] = [
                    'user_id' => $userId,
                    'batch_id' => $request->batch_name,
                    'name' => $labelData['name'],
                    'surname' => $labelData['surname'],
                    'number' => $labelData['number'] ?? null,
                    'designation' => $labelData['designation'] ?? null,
                    'company_name' => $labelData['company_name'] ?? null,
                    'stall_number' => $labelData['stall_number'] ?? null,
                    'status' => false, // Default pending
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            LabelPrint::insert($labelPrints);

            $createdLabels = LabelPrint::byBatch($batchId)->get();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Labels created successfully',
                'data' => [
                    'batch_id' => $batchId,
                    'total_created' => count($labelPrints),
                    'labels' => $createdLabels,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Failed to create labels',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a single entry to an existing batch.
     */
    public function addToBatch(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'batch_id' => 'required|string',
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'surname' => 'required|string|max:255',
            'number' => 'nullable|string|max:50',
            'designation' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'stall_number' => 'nullable|string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $labelPrint = LabelPrint::create([
                'user_id' => $request->user_id,
                'batch_id' => $request->batch_id,
                'name' => $request->name,
                'surname' => $request->surname,
                'number' => $request->number,
                'designation' => $request->designation,
                'company_name' => $request->company_name,
                'stall_number' => $request->stall_number,
                'status' => false, // Default pending
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Label added to batch successfully',
                'data' => $labelPrint,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to add label to batch',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified label print.
     */
    public function show(int $id): JsonResponse
    {
        $labelPrint = LabelPrint::with('user:id,name,email')->find($id);

        if (!$labelPrint) {
            return response()->json([
                'status' => false,
                'message' => 'Label print not found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $labelPrint,
        ]);
    }

    /**
     * Get all label prints by batch ID.
     */
    public function getByBatch(string $batchId): JsonResponse
    {
        $labelPrints = LabelPrint::byBatch($batchId)
            ->with('user:id,name,email')
            ->get();

        if ($labelPrints->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No labels found for this batch',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'batch_id' => $batchId,
                'total' => $labelPrints->count(),
                'pending_count' => $labelPrints->where('status', false)->count(),
                'printed_count' => $labelPrints->where('status', true)->count(),
                'labels' => $labelPrints,
            ],
        ]);
    }

    /**
     * Mark all labels in a batch as printed.
     */
    public function markBatchPrinted(string $batchId): JsonResponse
    {
        $updated = LabelPrint::byBatch($batchId)
            ->pending()
            ->update(['status' => true]);

        if ($updated === 0) {
            return response()->json([
                'status' => false,
                'message' => 'No pending labels found for this batch',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Batch marked as printed',
            'data' => [
                'batch_id' => $batchId,
                'updated_count' => $updated,
            ],
        ]);
    }

    /**
     * Update status for multiple label prints.
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:label_prints,id',
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updatedCount = LabelPrint::whereIn('id', $request->ids)
            ->update(['status' => $request->status]);

        return response()->json([
            'status' => true,
            'message' => 'Labels status updated successfully',
            'data' => [
                'updated_count' => $updatedCount,
            ],
        ]);
    }

    /**
     * Update a single label print.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $labelPrint = LabelPrint::find($id);

        if (!$labelPrint) {
            return response()->json([
                'status' => false,
                'message' => 'Label print not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'surname' => 'sometimes|string|max:255',
            'number' => 'nullable|string|max:50',
            'designation' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'stall_number' => 'nullable|string|max:50',
            'status' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $labelPrint->update($request->only([
            'name',
            'surname',
            'number',
            'designation',
            'company_name',
            'stall_number',
            'status',
        ]));

        return response()->json([
            'status' => true,
            'message' => 'Label print updated successfully',
            'data' => $labelPrint->fresh(),
        ]);
    }

    /**
     * Remove the specified label print.
     */
    public function destroy(int $id): JsonResponse
    {
        $labelPrint = LabelPrint::find($id);

        if (!$labelPrint) {
            return response()->json([
                'status' => false,
                'message' => 'Label print not found',
            ], 404);
        }

        $labelPrint->delete();

        return response()->json([
            'status' => true,
            'message' => 'Label print deleted successfully',
        ]);
    }

    /**
     * Delete all labels in a batch.
     */
    public function destroyBatch(string $batchId): JsonResponse
    {
        $deleted = LabelPrint::byBatch($batchId)->delete();

        if ($deleted === 0) {
            return response()->json([
                'status' => false,
                'message' => 'No labels found for this batch',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Batch deleted successfully',
            'data' => [
                'batch_id' => $batchId,
                'deleted_count' => $deleted,
            ],
        ]);
    }
}
