<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// use Storage;

class SettingController extends Controller
{
    public function index()
    {
       $settings = Setting::select('id', 'app_name','auth_logo','copyright','copyright_link','favicon','live_user','logo','meta_description','meta_tag','meta_title','missed_call_no','mo_logo','notify_req','site_credit','whatsapp_number')->first();
        return response()->json(['status' => true, 'data' => $settings], 200);
    }

    public function store(Request $request)
    {
        try {
            function storeFile($file, $folder = 'setting', $disk = 'public')
            {
                $filename = 'get-your-ticket-' . uniqid() . '_' . $file->getClientOriginalName();
                if (Storage::disk($disk)->exists('uploads/' . $folder . '/' . $filename)) {
                    Storage::disk($disk)->delete('uploads/' . $folder . '/' . $filename);
                }
                $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
                return Storage::disk($disk)->url($path);
            }

            $settings = Setting::firstOrNew([]);

            // Update fields only if they exist in the request
            $settings->app_name = $request->input('app_name', $settings->app_name);
            $settings->meta_title = $request->input('meta_title', $settings->meta_title);
            $settings->meta_tag = $request->input('meta_tag', $settings->meta_tag);
            $settings->meta_description = $request->input('meta_description', $settings->meta_description);
            $settings->copyright = $request->input('copyright', $settings->copyright);
            $settings->copyright_link = $request->input('copyright_link', $settings->copyright_link);
            $settings->complimentary_attendee_validation = $request->input('complimentary_attendee_validation', $settings->complimentary_attendee_validation);
            $settings->footer_address = $request->input('footer_address', $settings->footer_address);
            $settings->footer_contact = $request->input('footer_contact', $settings->footer_contact);
            $settings->site_credit = $request->input('site_credit', $settings->site_credit);
            $settings->missed_call_no = $request->input('missed_call_no', $settings->missed_call_no);
            $settings->whatsapp_number = $request->input('whatsapp_number', $settings->whatsapp_number);
            $settings->footer_email = $request->input('footer_email', $settings->footer_email);
            $settings->footer_whatsapp_number = $request->input('footer_whatsapp_number', $settings->footer_whatsapp_number);
            $settings->notify_req = $request->input('notify_req', $settings->notify_req);

            // Handle file uploads
            if ($request->hasFile('logo')) {
                $settings->logo = storeFile($request->file('logo'));
            }
            if ($request->hasFile('mo_logo')) {
                $settings->mo_logo = storeFile($request->file('mo_logo'));
            }
            if ($request->hasFile('favicon')) {
                $settings->favicon = storeFile($request->file('favicon'));
            }
            if ($request->hasFile('footer_logo')) {
                $settings->footer_logo = storeFile($request->file('footer_logo'));
            }
            if ($request->hasFile('nav_logo')) {
                $settings->nav_logo = storeFile($request->file('nav_logo'));
            }
            if ($request->hasFile('auth_logo')) {
                $settings->auth_logo = storeFile($request->file('auth_logo'));
            }
            if ($request->hasFile('footer_bg')) {
                $settings->footer_bg = storeFile($request->file('footer_bg'));
            }

            // Save the settings
            $settings->save();

            return response()->json(['status' => true, 'success' => 'Settings saved successfully.'], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'error' => 'Failed to save settings.', 'message' => $e->getMessage()], 500);
        }
    }


    public function updateLiveUser(Request $request, $id)
    {
        // Validate the request
        $request->validate([
            'live_user' => 'required|integer', // Ensure live_user is provided and is an integer
        ]);

        try {
            // Find the setting by ID
            $setting = Setting::findOrFail($id);

            // Update the live_user field
            $setting->live_user = $request->live_user;
            $setting->save();

            return response()->json(['status' => true, 'message' => 'Live user updated successfully', 'setting' => $setting], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Setting not found or update failed'], 404);
        }
    }

    public function footerDataGet()
    {
        $settings = Setting::first();
        return response()->json(['status' => true, 'data' => $settings], 200);
    }

    private function storeFile($file, $folder, $disk = 'public')
    {
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
        return Storage::disk($disk)->url($path);
    }
}
