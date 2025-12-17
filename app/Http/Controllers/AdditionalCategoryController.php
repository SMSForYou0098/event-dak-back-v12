<?php

namespace App\Http\Controllers;

use App\Models\AddCate;
use App\Models\User;
use Illuminate\Http\Request;

class AdditionalCategoryController extends Controller
{
    public function index($id)
    {
        // ID thi user fetch karo
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        $roles = $user->getRoleNames();

        // Admin check
        if ($roles->contains('Admin')) {
            $addCateData = AddCate::orderBy('created_at', 'desc')->get();
        }
        // Organizer check
        else if ($roles->contains('Organizer')) {
            $addCateData = AddCate::where('org_id', $user->id)->orderBy('created_at', 'desc')->get();
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        return response()->json([
            'status' => true,
            'data' => $addCateData
        ], 200);
    }

    public function store(Request $request)
    {
        try {
            $addCateData = new AddCate();
            $addCateData->user_id = $request->user_id;
            $addCateData->type = $request->type;
            $addCateData->title = $request->title;
            
            $addCateData->save();

            return response()->json([
                'status' => true,
                'message' => 'AdditionalCategory created successfully',
                'data' => $addCateData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create addCateData',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // 1️⃣ Fetch existing venue
            $addCateData = AddCate::findOrFail($id);

            // 2️⃣ Update text fields
            $addCateData->user_id = $request->user_id ?? $addCateData->user_id;
            $addCateData->type = $request->type ?? $addCateData->type;
            $addCateData->title = $request->title ?? $addCateData->title;
          
            $addCateData->save();

            return response()->json([
                'status'  => true,
                'message' => 'AdditionalCategory updated successfully',
                'data'    => $addCateData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update addCateData',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        $addCateData = AddCate::find($id);

        if (!$addCateData) {
            return response()->json(['status' => false, 'message' => 'AdditionalCategory not found'], 200);
        }

        return response()->json(['status' => true, 'data' => $addCateData], 200);
    }

    public function destroy(string $id)
    {
        $addCateData = AddCate::where('id', $id)->firstOrFail();
        if (!$addCateData) {
            return response()->json(['status' => false, 'message' => 'AdditionalCategory not found'], 200);
        }

        $addCateData->delete();
        return response()->json(['status' => true, 'message' => 'AdditionalCategory deleted successfully'], 200);
    }
}
