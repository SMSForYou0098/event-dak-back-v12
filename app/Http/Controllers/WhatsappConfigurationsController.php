<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WhatsappApi;
use App\Models\WhatsappConfigurations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsappConfigurationsController extends Controller
{

    public function show($id)
    {
        $config = WhatsappConfigurations::first();

        if (!$config) {
            return response()->json(['message' => 'Configuration not found'], 404);
        }

        return response()->json(['status' => true, 'message' => 'Configuration  successfully!', 'data' => $config], 200);
    }

    public function store(Request $request)
    {
        try {
            // Check if user is Admin
            $user = Auth::user();
            
            if (!$user->hasRole('Admin')) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized. Only Admin can configure WhatsApp settings.'
                ], 403);
            }

            $validated = $request->validate([
                'api_key' => 'required|string',
            ]);

            // Always update or create single entry for the admin
            $config = WhatsappConfigurations::updateOrCreate(
                ['user_id' => $user->id], // Find by user_id
                ['api_key' => $validated['api_key']] // Update api_key
            );

            return response()->json([
                'status' => true,
                'message' => 'WhatsApp configuration saved successfully!',
                'data' => $config
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to save configuration',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $configData = WhatsappConfigurations::findOrFail($id);

            $configData->delete();

            return response()->json(['status' => true, 'message' => 'Configuration deleted successfully!'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to delete configuration', 'error' => $e->getMessage()], 500);
        }
    }

    public function listData()
    {
        $WhatsappApiAllData = WhatsappApi::all();

        if (!$WhatsappApiAllData) {
            return response()->json(['message' => 'Whatsapp Api All Data not found'], 404);
        }

        return response()->json(['status' => true, 'message' => 'Configuration  successfully!', 'WhatsappApiAllData' => $WhatsappApiAllData], 200);
    }

    public function list($id)
    {
        $WhatsappApi = WhatsappApi::where('user_id', $id)->get();

        if (!$WhatsappApi) {
            return response()->json(['message' => 'Configuration not found'], 404);
        }

        return response()->json(['status' => true, 'message' => 'Configuration  successfully!', 'data' => $WhatsappApi], 200);
    }

    public function storeApi(Request $request)
    {
        try {
            $configData = new WhatsappApi();

            $configData->user_id = $request->input('user_id');
            $configData->title = $request->input('title');
            $configData->variables = $request->input('variables');
            $configData->template_name = $request->input('template_name');
            $configData->url = $request->input('url');
            $configData->custom = $request->input('custom');

            $configData->save();

            return response()->json(['status' => true, 'message' => 'Configuration saved successfully!', 'data' => $configData], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to create user', 'error' => $e->getMessage()], 500);
        }
    }

    public function updateApi(Request $request, $id)
    {
        try {
            $configData = WhatsappApi::findOrFail($id);

            $configData->title = $request->input('title');
            $configData->user_id = $request->input('user_id');
            $configData->variables = $request->input('variables');
            $configData->template_name = $request->input('template_name');
            $configData->url = $request->input('url');
            $configData->custom = $request->input('custom');

            $configData->save();

            return response()->json(['status' => true, 'message' => 'Configuration updated successfully!', 'data' => $configData], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to update configuration', 'error' => $e->getMessage()], 500);
        }
    }

    public function deleteApi($id)
    {
        try {
            $configData = WhatsappApi::findOrFail($id);

            $configData->delete();

            return response()->json(['status' => true, 'message' => 'Configuration deleted successfully!'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to delete configuration', 'error' => $e->getMessage()], 500);
        }
    }

    public function whatsappData($id = null,$title)
    {
        $config = WhatsappConfigurations::where('user_id', $id)->first();

        if (!$config) {

            $adminUsers = User::whereHas('roles', function ($query) {
                $query->where('name', 'Admin');
            })->first();

            if ($adminUsers) {
                $config = WhatsappConfigurations::where('user_id', $adminUsers->id)->first();
            }
        }
        $WhatsappApi = WhatsappApi::where('title', $title)->first();

        if ($config) {
            return response()->json(['status' => true, 'message' => 'whatsapp successfully!', 'data' => $config, 'WhatsappApi'=> $WhatsappApi], 200);
        } else {
            return response()->json(['message' => 'Configuration not found'], 404);
        }
    }

    public function whatsappTitleData($title)
    {
        $config = WhatsappApi::where('title', $title)->first();

        if ($config) {
            return response()->json(['status' => true, 'message' => 'whatsapp successfully!', 'data' => $config], 200);
        } else {
            return response()->json(['message' => 'Configuration not found'], 404);
        }
    }
}
