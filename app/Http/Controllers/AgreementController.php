<?php

namespace App\Http\Controllers;

use App\Models\Agreement;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Models\UserAgreement;
use App\Jobs\SendEmailJob;
use Illuminate\Http\Request;
use Storage;

class AgreementController extends Controller
{
    public function index()
    {
        try {
            $agreements = Agreement::orderBy('created_at', 'desc')->get();

            return response()->json([
                'status' => true,
                'data' => $agreements
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch agreements',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {

            $agreementData = new Agreement();
            $agreementData->title   = $request->title;
            $agreementData->content = $request->content;
            $agreementData->status  = $request->status ?? true;
            $agreementData->signature_type = $request->signature_type;


            // ===============================
            // ✅ 1️⃣ DRAW SIGNATURE (base64) — FIXED & WORKING
            // ===============================

            if ($request->signature_type === 'draw' && !empty($request->signature_image)) {

                $base64Image = $request->signature_image;

                // Clean base64 header if exists
                if (str_contains($base64Image, ';base64,')) {
                    [$type, $base64Image] = explode(';base64,', $base64Image);
                    $imageType = explode('image/', $type)[1] ?? 'png';
                } else {
                    $imageType = 'png';
                }

                // Validate allowed image types
                if (!in_array($imageType, ['png', 'jpeg', 'jpg', 'webp'])) {
                    throw new \Exception('Invalid signature image type');
                }

                $imageData = base64_decode($base64Image, true); // strict mode

                if ($imageData === false) {
                    throw new \Exception('Invalid base64 signature image');
                }

                // Optional: Validate it's actually an image
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($imageData);
                if (!str_starts_with($mimeType, 'image/')) {
                    throw new \Exception('Invalid image data');
                }

                $tempFile = tempnam(sys_get_temp_dir(), 'sig_');
                file_put_contents($tempFile, $imageData);

                try {
                    $uploadedFile = new \Illuminate\Http\UploadedFile(
                        $tempFile,
                        'signature_' . time() . '.' . $imageType,
                        'image/' . $imageType,
                        null,
                        true
                    );

                    $folder = 'signatures/' . uniqid();
                    $agreementData->signature_image = $this->storeFile($uploadedFile, $folder);

                    $agreementData->signature_text = null;
                    $agreementData->signature_font = null;
                    $agreementData->signature_font_style = null;
                } finally {
                    // Clean up temp file
                    if (file_exists($tempFile)) {
                        unlink($tempFile);
                    }
                }
            }

            // ===============================
            // ✅ 2️⃣ TYPED SIGNATURE
            // ===============================
            if ($request->signature_type === 'type') {

                $agreementData->signature_text = $request->signature_text;
                $agreementData->signature_font = $request->signature_font;
                $agreementData->signature_font_style = $request->signature_font_style;
                $agreementData->signature_image = null;
            }

            // ===============================
            // ✅ 3️⃣ UPLOAD SIGNATURE (FILE)
            // ===============================
            if ($request->signature_type === 'upload' && $request->hasFile('signature_image')) {

                $folder = 'signatures/' . uniqid();

                $agreementData->signature_image = $this->storeFile(
                    $request->file('signature_image'),
                    $folder
                );

                $agreementData->signature_text = null;
                $agreementData->signature_font = null;
                $agreementData->signature_font_style = null;
            }

            $agreementData->save();

            return response()->json([
                'status'  => true,
                'message' => 'Agreement created successfully',
                'data'    => $agreementData,
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'status'  => false,
                'message' => 'Failed to create Agreement',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $agreementData = Agreement::find($id);

            if (!$agreementData) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Agreement not found'
                ], 404);
            }

            // ✅ Basic fields
            $agreementData->title   = $request->title   ?? $agreementData->title;
            $agreementData->content = $request->content ?? $agreementData->content;
            $agreementData->status  = $request->status  ?? $agreementData->status;

            // ✅ Update signature type if coming
            if ($request->has('signature_type')) {
                $agreementData->signature_type = $request->signature_type;
            }

            // ✅ Ensure directory exists
            $folderPath = public_path('uploads/signatures');
            if (!file_exists($folderPath)) {
                mkdir($folderPath, 0777, true);
            }

            // ===============================
            // ✅ 1️⃣ DRAW SIGNATURE (base64)
            // ===============================
            if ($request->signature_type === 'draw' && !empty($request->signature_image)) {
                // Store the full base64 string directly in the database
                $agreementData->signature_image = $request->signature_image;
                $agreementData->signature_text = null;
                $agreementData->signature_font = null;
                $agreementData->signature_font_style = null;
            }

            // ===============================
            // ✅ 2️⃣ TYPED SIGNATURE
            // ===============================
            if ($request->signature_type === 'type') {

                $agreementData->signature_text = $request->signature_text;
                $agreementData->signature_font = $request->signature_font;
                $agreementData->signature_font_style = $request->signature_font_style;
                $agreementData->signature_image = null;
            }

            // ===============================
            // ✅ 3️⃣ UPLOAD SIGNATURE (FILE)
            // ===============================
            if ($request->signature_type === 'upload' && $request->hasFile('signature_image')) {

                $file = $request->file('signature_image');
                $fileName = 'signature_' . time() . '.' . $file->getClientOriginalExtension();
                $file->move($folderPath, $fileName);

                $agreementData->signature_image = 'uploads/signatures/' . $fileName;
                $agreementData->signature_text = null;
                $agreementData->signature_font = null;
                $agreementData->signature_font_style = null;
            }

            $agreementData->save();
            return response()->json([
                'status'  => true,
                'message' => 'Agreement updated successfully',
                'data'    => $agreementData
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to update agreement',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $agreementData = Agreement::find($id);

            if (!$agreementData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Agreement not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $agreementData
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch agreement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $agreementData = Agreement::find($id);

            if (!$agreementData) {
                return response()->json([
                    'status' => false,
                    'message' => 'Agreement not found'
                ], 404);
            }

            $agreementData->delete(); // Soft delete

            return response()->json([
                'status' => true,
                'message' => 'Agreement deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete agreement',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function onboardingList()
    {
        $data = User::where('approval_request', true)
            ->select('id', 'name', 'number', 'email', 'organisation')
            ->with(['organizerSignature' => function ($query) {
                $query->select('id', 'user_id', 'signatory_name', 'signature_type', 'signature_text', 'signature_font', 'signature_font_style', 'signature_image', 'signing_date');
            }])
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Pending organizers list',
            'data' => $data
        ], 200);
    }

    public function organizerAction(Request $request)
    {
       // return $request->all();
        try {
            $user = User::findOrFail($request->id);
            $userAgreement = null;
            $agreementUrl = null;

            if ($request->action === 'approve') {
                // Create user agreement record if agreement_id is provided
                if ($request->agreement_id) {
                    $agreement = Agreement::find($request->agreement_id);
                    
                    if (!$agreement) {
                        return response()->json([
                            'status' => false,
                            'message' => 'Agreement template not found',
                        ], 404);
                    }

                    try {
                        // Create the user agreement record first to get the ID
                        $userAgreement = UserAgreement::updateOrCreate(
                            [
                                'user_id' => $user->id,
                                'agreement_id' => $agreement->id,
                            ],
                            [
                                'status' => 'pending',
                            ]
                        );

                        \Log::info('UserAgreement created/updated', [
                            'id' => $userAgreement->id,
                            'user_id' => $user->id,
                            'agreement_id' => $agreement->id
                        ]);

                    } catch (\Exception $e) {
                        \Log::error('Failed to create UserAgreement: ' . $e->getMessage());
                        return response()->json([
                            'status' => false,
                            'message' => 'Failed to create user agreement',
                            'error' => $e->getMessage()
                        ], 500);
                    }

                    // Generate the agreement URL
                    $baseUrl = rtrim(env('ALLOWED_DOMAIN'), '/');
                    $agreementUrl = $baseUrl . '/agreement/preview/' . $userAgreement->id;

                    // Send "Agreement Confirmed" email first
                    $emailSent = $this->sendAgreementConfirmedEmail($user, $agreement, $agreementUrl);

                    if (!$emailSent) {
                        return response()->json([
                            'status' => false,
                            'message' => 'Failed to send agreement email. Approval not completed.',
                        ], 500);
                    }
                }

                // Only approve after email is sent successfully
                $user->activity_status = true;
                $user->approval_request = false;
                $user->save();
                $message = 'Organizer Approved Successfully';

            } else {
                $user->activity_status = false;
                $user->approval_request = false;
                $user->save();
                $message = 'Organizer Rejected Successfully';
            }

            return response()->json([
                'status' => true,
                'message' => $message,
                'user_agreement' => $userAgreement,
                'agreement_url' => $agreementUrl,
            ], 200);

        } catch (\Exception $e) {
            \Log::error('organizerAction failed: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while processing the request',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send Agreement Confirmed email to user
     * @return bool - true if email dispatched successfully, false otherwise
     */
    private function sendAgreementConfirmedEmail($user, $agreement, $agreementUrl)
    {
        try {
            $emailTemplate = EmailTemplate::where('template_id', 'Agreement Confirmed')->first();

            if (!$emailTemplate) {
                \Log::error('Agreement Confirmed email template not found in database');
                return false;
            }

            $body = str_replace(
                ['{{link}}', '{{url}}', '{{username}}', '{{date}}', '{{agreement_title}}'],
                [$agreementUrl, $agreementUrl, $user->name, now()->format('d/m/Y'), $agreement->title],
                $emailTemplate->body
            );

            $details = [
                'email' => $user->email,
                'title' => $emailTemplate->subject,
                'body' => $body,
            ];

            dispatch(new SendEmailJob($details));

            \Log::info('Agreement Confirmed email dispatched', [
                'user_email' => $user->email,
                'agreement_title' => $agreement->title
            ]);

            return true;

        } catch (\Exception $e) {
            \Log::error('Failed to send Agreement Confirmed email: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'agreement_id' => $agreement->id,
                'error_trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Get user agreement by ID (for frontend to display)
     * Returns: UserAgreement, User info, Organizer Signature, Full Agreement data
     */
    public function previewAgreement($id)
    {
        try {
            $userAgreement = UserAgreement::with([
                'user:id,name,email,number,organisation',
                'user.organizerSignature:id,user_id,signatory_name,signature_type,signature_text,signature_font,signature_font_style,signature_image,signing_date',
                'agreement'
            ])->findOrFail($id);

            return response()->json([
                'status' => true,
                'data' => [
                    'id' => $userAgreement->id,
                    'status' => $userAgreement->status,
                    'content' => $userAgreement->content,
                    'signed_at' => $userAgreement->signed_at,
                    'created_at' => $userAgreement->created_at,
                    'updated_at' => $userAgreement->updated_at,
                    'user' => $userAgreement->user ? [
                        'id' => $userAgreement->user->id,
                        'name' => $userAgreement->user->name,
                        'email' => $userAgreement->user->email,
                        'number' => $userAgreement->user->number,
                        'organisation' => $userAgreement->user->organisation,
                    ] : null,
                    'organizer_signature' => $userAgreement->user?->organizerSignature ? [
                        'id' => $userAgreement->user->organizerSignature->id,
                        'signatory_name' => $userAgreement->user->organizerSignature->signatory_name,
                        'signature_type' => $userAgreement->user->organizerSignature->signature_type,
                        'signature_text' => $userAgreement->user->organizerSignature->signature_text,
                        'signature_font' => $userAgreement->user->organizerSignature->signature_font,
                        'signature_font_style' => $userAgreement->user->organizerSignature->signature_font_style,
                        'signature_image' => $userAgreement->user->organizerSignature->signature_image,
                        'signing_date' => $userAgreement->user->organizerSignature->signing_date,
                    ] : null,
                    'agreement' => $userAgreement->agreement ? [
                        'id' => $userAgreement->agreement->id,
                        'title' => $userAgreement->agreement->title,
                        'content' => $userAgreement->agreement->content,
                        'status' => $userAgreement->agreement->status,
                        'signature_type' => $userAgreement->agreement->signature_type,
                        'signature_text' => $userAgreement->agreement->signature_text,
                        'signature_font' => $userAgreement->agreement->signature_font,
                        'signature_font_style' => $userAgreement->agreement->signature_font_style,
                        'signature_image' => $userAgreement->agreement->signature_image,
                    ] : null,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Agreement not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Verify user for agreement signing
     * Accepts either actual password OR custom password (first 4 letters of name + last 4 digits of number)
     * 
     * @param Request $request - user_agreement_id, password
     */
    public function verifyUserForAgreement(Request $request)
    {
        try {
            $request->validate([
                'user_agreement_id' => 'required|integer',
                'password' => 'required|string',
            ]);

            // Find user agreement with user
            $userAgreement = UserAgreement::with('user')->find($request->user_agreement_id);

            if (!$userAgreement) {
                return response()->json([
                    'status' => false,
                    'message' => 'User agreement not found',
                ], 404);
            }

            $user = $userAgreement->user;

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Check if agreement is already signed
            if ($userAgreement->status === 'signed') {
                return response()->json([
                    'status' => false,
                    'message' => 'Agreement has already been signed',
                ], 400);
            }

            $inputPassword = $request->password;
            $isVerified = false;

            // Method 1: Check actual password using Laravel's Hash
            if (\Hash::check($inputPassword, $user->password)) {
                $isVerified = true;
            }

            // Method 2: Check custom password (first 4 letters of name + last 4 digits of number)
            if (!$isVerified) {
                $customPassword = $this->generateCustomPassword($user->name, $user->number);
                if ($inputPassword === $customPassword) {
                    $isVerified = true;
                }
            }

            if ($isVerified) {
                return response()->json([
                    'status' => true,
                    'message' => 'User verified successfully',
                    'user_agreement_id' => $userAgreement->id,
                    'user_id' => $user->id,
                ], 200);
            }

            return response()->json([
                'status' => false,
                'message' => 'Invalid password',
            ], 401);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('verifyUserForAgreement failed: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'An error occurred during verification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate custom password from user's name and number
     * Format: First 4 letters of name (or full name if less than 4) + Last 4 digits of number
     * 
     * @param string $name
     * @param string $number
     * @return string
     */
    private function generateCustomPassword($name, $number)
    {
        // Remove spaces and get first 4 letters (or less if name is shorter)
        $cleanName = preg_replace('/\s+/', '', $name); // Remove all spaces
        $nameLength = strlen($cleanName);
        $namePart = strtolower(substr($cleanName, 0, min(4, $nameLength)));

        // Get last 4 digits of number
        $cleanNumber = preg_replace('/\D/', '', $number); // Remove non-digits
        $numberPart = substr($cleanNumber, -4);

        return $namePart . $numberPart;
    }

    private function storeFile($file, $folder, $disk = 'public')
    {
        if (!$file) return null;

        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
        return Storage::disk($disk)->url($path);
    }
}
