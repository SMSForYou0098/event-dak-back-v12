<?php

namespace App\Http\Controllers;

use App\Models\AiApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AiApiKeyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $keys = AiApiKey::all();
        return response()->json([
            'status' => true,
            'data' => $keys
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'model' => 'required|string|max:255',
            'apikey' => 'required|string|max:255',
            'status' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $key = AiApiKey::create($request->all());

        return response()->json([
            'status' => true,
            'message' => 'API Key created successfully',
            'data' => $key
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $key = AiApiKey::find($id);

        if (!$key) {
            return response()->json([
                'status' => false,
                'message' => 'API Key not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $key
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $key = AiApiKey::find($id);

        if (!$key) {
            return response()->json([
                'status' => false,
                'message' => 'API Key not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'model' => 'sometimes|string|max:255',
            'apikey' => 'sometimes|string|max:255',
            'status' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $key->update($request->all());

        return response()->json([
            'status' => true,
            'message' => 'API Key updated successfully',
            'data' => $key
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $key = AiApiKey::find($id);

        if (!$key) {
            return response()->json([
                'status' => false,
                'message' => 'API Key not found'
            ], 404);
        }

        $key->delete();

        return response()->json([
            'status' => true,
            'message' => 'API Key deleted successfully'
        ]);
    }
}
