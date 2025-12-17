<?php

namespace App\Http\Controllers;

use App\Models\PromoteOrg;
use Illuminate\Http\Request;
use Storage;


class PromoteOrgController extends Controller
{

    public function index(Request $request)
    {

        $promoteData = PromoteOrg::with([
            'org:id,city,organisation'
        ])
            ->orderBy('sr_no', 'asc')
            ->get();

        if ($promoteData->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'promoteData not found'
            ], 200);
        }
        return response()->json([
            'status' => true,
            'data' => $promoteData,
        ], 200);
    }

    public function store(Request $request)
    {
        try {
            $maxSrNo = PromoteOrg::max('sr_no');
            $srNo = $maxSrNo ? $maxSrNo + 1 : 1;

            $promoteData = new PromoteOrg();
            $promoteData->sr_no = $srNo;
            $promoteData->user_id = $request->user_id;
            $promoteData->org_id = $request->org_id;

            $folder = 'PromoteOrg/' . $request->org_id;

            if ($request->hasFile('image')) {
                $promoteData->image = $this->storeFile($request->file('image'), $folder);
            }

            $promoteData->save();

            return response()->json(['status' => true, 'message' => 'promoteData craete successfully', 'data' => $promoteData], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to promoteData '], 404);
        }
    }

    public function show($id)
    {
        $promoteData = PromoteOrg::find($id);

        if (!$promoteData) {
            return response()->json(['status' => false, 'message' => 'promoteData not found'], 200);
        }

        return response()->json(['status' => true, 'data' => $promoteData], 200);
    }

    public function update(Request $request, $id)
    {
        try {
            $promoteData = PromoteOrg::findOrFail($id); // existing record fetch

            $promoteData->user_id = $request->user_id ?? $promoteData->user_id;
            $promoteData->org_id = $request->org_id ?? $promoteData->org_id;

            $folder = 'PromoteOrg/' . ($request->org_id ?? $promoteData->org_id);

            if ($request->hasFile('image')) {

                if (!empty($promoteData->image)) {
                    $oldImagePath = str_replace('/storage/', '', $promoteData->image);
                    if (Storage::disk('public')->exists($oldImagePath)) {
                        Storage::disk('public')->delete($oldImagePath);
                    }
                }

                // store new image
                $promoteData->image = $this->storeFile($request->file('image'), $folder);
            }

            $promoteData->save();

            return response()->json(['status' => true, 'message' => 'promoteData updated successfully', 'data' => $promoteData], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to update promoteData'], 404);
        }
    }

    public function destroy(string $id)
    {
        $promoteData = PromoteOrg::where('id', $id)->firstOrFail();
        if (!$promoteData) {
            return response()->json(['status' => false, 'message' => 'promoteData not found'], 404);
        }

        $promoteData->delete();
        return response()->json(['status' => true, 'message' => 'promoteData deleted successfully'], 200);
    }

    private function storeFile($file, $folder, $disk = 'public')
    {
        if (!$file) return null;

        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
        return Storage::disk($disk)->url($path);
    }

    public function reorderPromote(Request $request)
    {
        try {
            $srNoCount = [];

            foreach ($request->data as $item) {
                if (isset($srNoCount[$item['sr_no']])) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Duplicate sr_no values detected: ' . $item['sr_no'],
                    ], 400);
                }
                $srNoCount[$item['sr_no']] = true;
            }

            foreach ($request->data as $item) {
                $promoteData = PromoteOrg::findOrFail($item['id']);
                $promoteData->sr_no = $item['sr_no'];
                $promoteData->save();
            }

            $updatedpromoteData = PromoteOrg::orderBy('sr_no')->get();

            return response()->json([
                'status' => true,
                'message' => 'promoteData rearranged successfully',
                'data' => $updatedpromoteData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to rearrange promoteData',
                'error' => $e->getMessage(),
            ], 404);
        }
    }
}
