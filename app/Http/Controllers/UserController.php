<?php

namespace App\Http\Controllers;

use App\Exports\UserExport;
use App\Http\Resources\UserEditResource;
use App\Jobs\SendEmailJob;
use App\Models\AgentEvent;
use App\Models\EmailTemplate;
use App\Models\Event;
use App\Models\Shop;
use App\Models\Ticket;
use App\Models\User;
use App\Models\BookingTax;
use App\Models\UserTicket;
use App\Models\WhatsappApi;
use App\Models\OrganizerSignature;
use App\Services\DateRangeService;
use App\Services\PermissionService;
use App\Services\SmsService;
use App\Services\UserService;
use App\Services\WhatsappService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService
    ) {}

    private function getAllReportingUserIds($organizerId)
    {
        $userIds = collect([$organizerId]);

        $children = User::where('reporting_user', $organizerId)->pluck('id');

        foreach ($children as $childId) {
            $userIds = $userIds->merge($this->getAllReportingUserIds($childId));
        }

        return $userIds->unique();
    }

    public function index(Request $request, PermissionService $permissionService, DateRangeService $dateRangeService)
    {
        $loggedInUser = Auth::user();
        $eventType = $request->type;

        $perPage = min($request->input('per_page', 15), 100);
        $page = $request->input('page', 1);
        $search = trim($request->input('search', ''));

        // Date range handling
        $startDate = null;
        $endDate = null;

        if ($eventType !== 'all') {
            $dateRange = $dateRangeService->parseDateRangeSafe($request);
            if (isset($dateRange['error'])) {
                return response()->json(['status' => false, 'message' => $dateRange['error']], 400);
            }
            $startDate = $dateRange['startDate'];
            $endDate = $dateRange['endDate'];
        }

        // Build base query
        $query = User::query()
            ->with([
                'roles:id,name',
                'reportingUser:id,name,organisation'
            ]);

        // Role-based filtering
        if ($loggedInUser->hasRole('Organizer')) {
            $query->where('reporting_user', $loggedInUser->id);
        } elseif (!$loggedInUser->hasRole('Admin')) {
            $query->where('id', $loggedInUser->id);
        }

        // Date filter
        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        // Search filter - Fixed version
        if ($search !== '') {
            $searchTerm = "%{$search}%";

            $query->where(function ($q) use ($searchTerm) {
                $q->where('users.name', 'ILIKE', $searchTerm)
                    ->orWhere('users.email', 'ILIKE', $searchTerm)
                    ->orWhereRaw('users.number::text ILIKE ?', [$searchTerm])
                    ->orWhere('users.organisation', 'ILIKE', $searchTerm)
                    // Search by role name - specify table name to avoid ambiguity
                    ->orWhereHas('roles', function ($rq) use ($searchTerm) {
                        $rq->where('roles.name', 'ILIKE', $searchTerm);
                    })
                    // Search by reporting user name - specify table and cast
                    ->orWhereRaw('users.reporting_user::bigint IN (
                  SELECT id FROM users 
                  WHERE name ILIKE ? AND deleted_at IS NULL
              )', [$searchTerm]);
            });
        }

        // Paginate
        $paginatedUsers = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        // Permission checks
        $permissions = $permissionService->check(['View User Number', 'View User Email']);
        $canViewContact = $permissions['View User Number'];
        $canViewEmail = $permissions['View User Email'];

        // Transform
        $allUsers = $paginatedUsers->getCollection()->map(fn($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'contact' => $canViewContact ? $user->number : null,
            'email' => $canViewEmail ? $user->email : null,
            'role_name' => $user->roles->first()?->name,
            'status' => $user->status,
            'activity_status' => $user->activity_status,
            'reporting_user' => $user->reportingUser?->name,
            'organisation' => $user->reportingUser?->organisation,
            'created_at' => $user->created_at,
            'authentication' => (int) $user->authentication,
        ]);

        return response()->json([
            'status' => true,
            'data' => $allUsers,
            'pagination' => [
                'current_page' => $paginatedUsers->currentPage(),
                'per_page' => $paginatedUsers->perPage(),
                'total' => $paginatedUsers->total(),
                'last_page' => $paginatedUsers->lastPage(),
            ]
        ]);
    }

    public function create(Request $request, SmsService $smsService, WhatsappService $whatsappService, PermissionService $permissionService)
    {
        try {
            $validation = $this->getUserValidationRules();
            $requiredVerification = $request->boolean('verification_required');
            $isOrgOnBoardReq =
                $request->header('X-Unique-Request') === 'org_form_1'
                || $requiredVerification;

            $request->validate($validation['rules'], $validation['messages']);
            $user = new User();
            $user->name = $request->name;
            $user->email = $request->email ?? $request->number . '@gyt.co.in';
            $user->number = $request->number;
            $user->email_verified_at = $isOrgOnBoardReq ? null : now();
            $user->company_name = $request->company_name;
            $user->address = $request->address;
            $user->organisation = $request->organisation;
            $user->alt_number = $request->alt_number;
            $user->pincode = $request->pincode;
            $user->state = $request->state;
            $user->city = $request->city;
            $user->bank_name = $request->bank_name;
            $user->bank_number = $request->bank_number;
            $user->bank_ifsc = $request->bank_ifsc;
            $user->bank_branch = $request->bank_branch;
            $user->bank_micr = $request->bank_micr;
            $user->tax_number = $request->tax_number;
            $user->reporting_user = $request->reporting_user;
            $user->authentication = $request->authentication;
            $user->payment_method = $request->payment_method;
            $user->agent_disc = $request->agent_disc;
            $user->status = true;
            $user->activity_status = true;
            $user->agreement_status = filter_var($request->agreement_status, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $user->org_type_of_company = $request->org_type_of_company;
            $user->org_office_address = $request->org_office_address;
            $user->org_gst_no = $request->org_gst_no;
            $user->pan_no = $request->pan_no;
            $user->account_holder = $request->account_holder;
            $user->brand_name = $request->brandName;
            $user->password = Hash::make($request->password);

            if ($isOrgOnBoardReq) {
                // Force Organizer role
                $request->merge([
                    'role_name' => 'Organizer'
                ]);

                $user->activity_status = false;     // Organizer auto status
                // $user->status = false;     // Organizer auto status
                // Email verification required - will be sent after save
            } else {
                // Users created by admin are auto-verified
                $user->email_verified_at = now();
            }

            // Handle file uploads using common method
            $this->handleFileUploads($request, $user, $request->name);

            $user->save();

            $userId = $user->id;
            $this->updateUserRole($request, $user);
            $this->taxStore($request, $userId);

            $methods = match ($request->role_name) {
                'Shop Keeper' => ['shopStore'],
                'Agent', 'Sponsor' => ['agentEventStore'],
                'Scanner' => ['agentEventStore', 'scannerGateStore'],
                default => [],
            };

            foreach ($methods as $method) {
                $this->$method($request, $userId);
            }

            if ($request->role_name == 'Agent' || $request->role_name == 'Sponsor' || $request->role_name == 'Accreditation' || $request->role_name == 'Scanner') {

                $this->agentEventStore($request, $userId);
            }
            // Send verification email only for organizer onboarding
            if ($isOrgOnBoardReq && $request->email) {
                try {
                    $this->sendRegisterMail($user);
                } catch (\Exception $e) {
                    // Log error but don't fail user creation
                    Log::error('Failed to send verification email: ' . $e->getMessage());
                }
            }

            //send whatsapp or sms
            $this->sendIntimation($request, $user, $smsService);

            return response()->json(['status' => true, 'message' => 'User Created Successfully', 'user' => $user], 201);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to create user', 'error' => $e->getMessage()], 500);
        }
    }

    private function updateUserRole($request, $user)
    {
        // If Organizer form
        if ($request->header('X-Unique-Request') === 'org_form_1') {
            $role = Role::where('name', 'Organizer')->first();
            if ($role) {
                $user->syncRoles([]);
                $user->assignRole($role);
            }
            return; // STOP → do not process below logic
        }

        // If role_id given
        if ($request->has('role_id') && $request->role_id) {
            $role = Role::find($request->role_id);
            if ($role) {
                $user->syncRoles([]);
                $user->assignRole($role);
            }
            return;
        }

        // Default → User role
        $defaultRole = Role::where('name', 'User')->first();
        if ($defaultRole) {
            $user->syncRoles([]);
            $user->assignRole($defaultRole);
        }
    }

    public function edit(string $id)
    {
        // Get user with eager loaded relationships
        $user = $this->userService->getUserForEdit($id);

        // Handle user not found
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Authorization check
        if (request()->user()->cannot('view', $user)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Get events and tickets data
        $additionalData = $this->userService->getEventsAndTickets($id);

        // Return formatted response
        return (new UserEditResource($user))
            ->withAdditionalData($additionalData);
    }



    public function update(Request $request, string $id, SmsService $smsService)
    {
        $user = User::findOrFail($id);
        if ($request->user()->cannot('update', $user)) {
            return response()->json(['status' => false, 'message' => 'Unauthorized'], 403);
        }

        $this->castBooleanFields($request, ['status', 'authentication', 'agreement_status']);
        $validation = $this->getUserValidationRules((int) $id);
        $request->validate($validation['rules'], $validation['messages']);
        try {

            $role = null;
            if ($request->has('name')) {
                $user->name = $request->name;
            }

            if ($request->has('email')) {
                $user->email = $request->email;
            }
            if ($request->has('password')) {
                $user->password = Hash::make($request->password);
            }

            if ($request->has('number')) {
                $user->number = $request->number;
            }
            if ($request->has('address')) {
                $user->address = $request->address;
            }
            if ($request->has('company_name')) {
                $user->company_name = $request->company_name;
            }

            if ($request->has('reporting_user')) {
                $user->reporting_user = $request->reporting_user;
            }

            if ($request->has('organisation')) {
                $user->organisation = $request->organisation;
            }
            if ($request->has('brandName')) {
                $user->brand_name = $request->brandName;
            }

            if ($request->has('alt_number')) {
                $user->alt_number = $request->alt_number;
            }

            if ($request->has('pincode')) {
                $user->pincode = $request->pincode;
            }

            if ($request->has('state')) {
                $user->state = $request->state;
            }

            if ($request->has('city')) {
                $user->city = $request->city;
            }

            if ($request->has('bank_name')) {
                $user->bank_name = $request->bank_name;
            }

            if ($request->has('bank_number')) {
                $user->bank_number = $request->bank_number;
            }

            if ($request->has('bank_ifsc')) {
                $user->bank_ifsc = $request->bank_ifsc;
            }

            if ($request->has('bank_branch')) {
                $user->bank_branch = $request->bank_branch;
            }

            if ($request->has('bank_micr')) {
                $user->bank_micr = $request->bank_micr;
            }

            if ($request->has('tax_number')) {
                $user->tax_number = $request->tax_number;
            }

            if ($request->has('qr_length')) {
                $user->qr_length = $request->qr_length;
            }
            if ($request->has('authentication')) {
                $user->authentication = $request->authentication;
            }
            if ($request->has('agent_disc')) {
                $user->agent_disc = $request->agent_disc;
            }

            if ($request->has('status')) {
                $user->status = $request->status;
            }

            if ($request->has('payment_method')) {
                $user->payment_method = $request->payment_method;
            }
            if ($request->has('org_type_of_company')) {
                $user->org_type_of_company = $request->org_type_of_company;
            }
            if ($request->has('org_office_address')) {
                $user->org_office_address = $request->org_office_address;
            }
            if ($request->has('org_gst_no')) {
                $user->org_gst_no = $request->org_gst_no;
            }
            if ($request->has('pan_no')) {
                $user->pan_no = $request->pan_no;
            }
            if ($request->has('account_holder')) {
                $user->account_holder = $request->account_holder;
            }

            if ($request->has('agreement_status')) {
                $user->agreement_status = filter_var($request->agreement_status, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
            // Handle file uploads using common method
            $this->handleFileUploads($request, $user, $user->name);

            if ($request->has('role_id') && $request->role_id) {
                $role = Role::find($request->role_id);

                if ($role) {
                    // Remove all current roles
                    $user->syncRoles([]);

                    // Assign the new role
                    $user->assignRole($role);
                }
            }

            $this->taxUpdate($request, $id);
            if ($request->role_name == 'Shop Keeper') {
                $this->shopUpdate($request, $id);
            }
            if ($request->role_name == 'Agent' || $request->role_name == 'Sponsor' || $request->role_name == 'Accreditation' || $request->role_name == 'Scanner') {

                $data = $this->agentEventStore($request, $id);
            }
            if ($request->role_name == 'Accreditation') {
                $this->userTicketStore($request, $id);
            }

            $user->save();

            // Handle organizer signature if activity_status is false (pending approval)
            if (!$user->activity_status) {
                $this->handleOrganizerSignature($request, $user);
            }

            //send whatsapp or sms
            $this->sendIntimation($request, $user, $smsService);


            return response()->json(['status' => true, 'message' => 'User Updated Successfully', 'role' => $role, 'user' => $user], 200);
        } catch (\Exception $e) {

            // Return an error response
            return response()->json(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function castBooleanFields(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $value = $request->input($field);
                $request->merge([
                    $field => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                ]);
            }
        }
    }

    private function getUserValidationRules(?int $id = null): array
    {
        $isUpdate = $id !== null;
        $requiredOrSometimes = $isUpdate ? 'sometimes' : 'required';

        return [
            'rules' => [
                // Core fields
                'name' => "{$requiredOrSometimes}|string|min:2|max:255|regex:/^[a-zA-Z\s]+$/",
                'number' => "{$requiredOrSometimes}|digits:10|regex:/^[6-9][0-9]{9}$/|unique:users,number" . ($id ? ",{$id}" : ''),
                'email' => ($isUpdate ? 'sometimes' : 'nullable') . "|email|max:255|unique:users,email" . ($id ? ",{$id}" : ',NULL,id,deleted_at,NULL'),
                'password' => ($isUpdate ? 'sometimes' : 'required') . "|string|min:8",

                // Personal details
                'company_name' => 'nullable|string|min:2|max:255',
                'address' => 'nullable|string|min:5|max:500',
                'organisation' => 'nullable|string|min:2|max:255',
                'alt_number' => 'nullable|digits:10|regex:/^[6-9][0-9]{9}$/',
                'pincode' => 'nullable|digits:6',
                'state' => 'nullable|string|min:2|max:100',
                'city' => 'nullable|string|min:2|max:100',

                // Bank details
                'bank_name' => 'nullable|string|min:2|max:100',
                'bank_number' => 'nullable|string|min:9|max:18|regex:/^[0-9]+$/',
                'bank_ifsc' => 'nullable|string|size:11|regex:/^[A-Z]{4}0[A-Z0-9]{6}$/',
                'bank_branch' => 'nullable|string|min:2|max:100',
                'bank_micr' => 'nullable|digits:9',
                'account_holder' => 'nullable|string|min:2|max:255|regex:/^[a-zA-Z\s]+$/',

                // Tax & Business
                'tax_number' => 'nullable|string|max:50',
                'org_gst_no' => 'nullable|string|size:15|regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/',
                'pan_no' => 'nullable|string|size:10|regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/',

                // Organization
                'org_type_of_company' => 'nullable|string|max:100',
                'org_office_address' => 'nullable|string|min:5|max:500',
                'org_name_signatory' => 'nullable|string|min:2|max:255',
                'org_signature_type' => 'nullable|string|max:50',

                // Other
                'reporting_user' => 'nullable|integer|exists:users,id',
                'authentication' => 'nullable|boolean|max:50',
                'payment_method' => 'nullable|string|max:50',
                'agent_disc' => 'nullable|numeric|min:0|max:100',
                'agreement_status' => 'nullable|boolean',
                'status' => 'nullable|boolean',
                'qr_length' => 'nullable|integer|min:1|max:100',

                // Files
                'photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
                'org_signatory_image' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
                'doc' => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:5120',

                // Role
                'role_id' => 'nullable|integer|exists:roles,id',
                'role_name' => 'nullable|string|exists:roles,name',
            ],

            'messages' => [
                'name.regex' => 'Name must contain only letters and spaces.',
                'name.min' => 'Name must be at least 2 characters.',
                'number.regex' => 'Please enter a valid Indian mobile number.',
                'number.digits' => 'Mobile number must be exactly 10 digits.',
                'number.unique' => 'This mobile number is already registered.',
                'email.unique' => 'This email is already registered.',
                'alt_number.regex' => 'Please enter a valid alternate mobile number.',
                'bank_ifsc.regex' => 'Please enter a valid IFSC code.',
                'org_gst_no.regex' => 'Please enter a valid GST number.',
                'pan_no.regex' => 'Please enter a valid PAN number.',
                'photo.max' => 'Photo size must be less than 2MB.',
                'doc.max' => 'Document size must be less than 5MB.',
            ]
        ];
    }

    public function CheckValidUser($id)
    {
        try {
            $user = User::where('id', $id)->with(['balance', 'pricingModel'])->get();
            $user->each(function ($user) {
                $user->latest_balance = $user->balance()->latest()->first();
                $user->pricing = $user->pricingModel()->latest()->first();
                unset($user->balance);
                unset($user->pricingModel);
            });
            $user_balance = $user[0]->latest_balance->total_credits ?? 00.00;
            $marketing_price = $user[0]->pricing->marketing_price;
            if ($user_balance < $marketing_price) {
                $user_balance = $user[0]->latest_balance->total_credits ?? 0;
                return response()->json(['status' => false, 'message' => 'insufficient credits', 'balance' => $user_balance]);
            } else {
                return response()->json(['status' => true, 'balance' => $user_balance]);
            }
        } catch (QueryException $e) {
            $errorMessage = $e->getMessage();
            return response()->json(['status' => false, 'message' => 'Query Exception: ' . $errorMessage]);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            return response()->json(['status' => false, 'message' => 'An error occurred while processing the request.' . $errorMessage]);
        }
    }

    public function checkEmail(Request $request)
    {
        $emailExists = false;
        $mobileExists = false;
        $email = $request->input('email');
        $mobile = $request->input('number');

        // Start by checking if both email and mobile are provided
        $query = User::query()->select('id', 'name', 'email', 'number', 'photo', 'doc', 'company_name');

        if ($mobile) {
            $query->orWhere('number', $mobile);
        }

        if ($email) {
            $query->orWhere('email', $email);
        }

        $user = $query->first();

        if ($user) {
            // Check if email exists for the matched user
            if ($email && $user->email == $email) {
                $emailExists = true;
            }

            // Check if mobile exists for the matched user
            if ($mobile && $user->number == $mobile) {
                $mobileExists = true;
            }

            // Handle case where email and mobile belong to different users
            $isEmailAndMobileFromDifferentUsers = false;
            if ($email && $mobile) {
                $otherUser = User::where('email', $email)->first();
                $otherMobileUser = User::where('number', $mobile)->first();

                if ($otherUser && $otherMobileUser && $otherUser->id != $otherMobileUser->id) {
                    $isEmailAndMobileFromDifferentUsers = true;
                }
            }

            return response()->json([
                'exists' => true,
                'message' => 'User exists',
                'email_exists' => $emailExists,
                'mobile_exists' => $mobileExists,
                'is_email_and_mobile_different_users' => $isEmailAndMobileFromDifferentUsers,
                'user' => $user
            ]);
        } else {
            return response()->json([
                'exists' => false,
                'message' => 'Both email and mobile are available'
            ]);
        }
    }

    public function checkMobile(Request $request)
    {
        $mobile = $request->input('number');
        $user = User::where('number', $mobile)->first();

        if ($user) {
            if (empty($user->email)) {
                return response()->json(['status' => true, 'message' => 'No email exists for this number.']);
            } else {
                return response()->json(['status' => false, 'message' => 'Email exists for this number.']);
            }
        } else {
            return response()->json(['status' => false, 'message' => 'Number not found in the users table.']);
        }
    }


    public function UpdateUserSecurity(Request $request)
    {
        try {
            $user = User::where('id', $request->id)->firstOrFail();

            $user->ip_auth = $request->ip_auth == true ? 'true' : 'false';
            $user->two_fector_auth = $request->two_fector_auth == true ? 'true' : 'false';
            $user->ip_addresses = $request->ip_addresses;
            $user->save();
            return response()->json(['status' => true, 'message' => 'Security Method Updated Successfully', 'email' => $user->email]);
        } catch (QueryException $e) {
            $errorMessage = $e->getMessage();
            return response()->json(['status' => false, 'message' => 'Query Exception: ' . $errorMessage]);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            return response()->json(['status' => false, 'message' => 'An error occurred while processing the request.' . $errorMessage]);
        }
    }

    public function checkPassword(Request $request)
    {
        $user = User::find($request->id);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $password = $request->password;

        if (Hash::check($password, $user->password)) {
            return response()->json(['message' => 'Password is correct, you are verified successfully'], 200);
        } else {
            return response()->json(['error' => 'Oops! Password is incorrect'], 401);
        }
    }

    public function CreditLimit(Request $request)
    {
        $user = User::firstOrFail($request->id);
        $user->low_credit_limit = $request->amount;
        $user->save();
        return response()->json(['message' => 'Limit Updated Successfully'], 200);
    }

    public function updateAlerts(Request $request, string $id)
    {
        try {
            $user = User::findOrFail($id); // Assuming $userId is the ID of the user you want to update

            if ($request->email_alerts) {
                $user->email_alerts = $request->email_alerts;
            } else if ($request->whatsapp_alerts) {
                $user->whatsapp_alerts = $request->whatsapp_alerts;
            } else if ($request->text_alerts) {
                $user->text_alerts = $request->text_alerts;
            }
            $user->save();

            return response()->json(['status' => true, 'message' => 'User Updated Successfully'], 200);
        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Error updating user: ' . $e->getMessage());

            // Return an error response
            return response()->json(['status' => false, 'message' => 'Failed to update user'], 500);
        }
    }

    public function lowBalanceUser($id)
    {
        $users = User::select('id', 'name', 'email', 'whatsapp_number', 'phone_number', 'email_alerts', 'whatsapp_alerts', 'text_alerts')
            ->with(['balance', 'pricingModel', 'ApiKey'])
            ->get();

        // Process each user to attach the latest balance and pricing information
        $filteredUsers = $users->filter(function ($user) {
            $totalCredits = $user->balance()->latest()->first();
            $user->latest_balance = optional($totalCredits)->total_credits;
            $user->pricing = $user->pricingModel()->latest()->first();
            $user->ApiKey = $user->ApiKey()->latest()->first();

            // Check if the latest balance is lower than the price_alert
            if ($user->latest_balance < optional($user->pricing)->price_alert) {
                return true;
            }
            return false;
        });
        // Remove unnecessary attributes
        $result = $filteredUsers->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'whatsapp_number' => $user->whatsapp_number,
                'phone_number' => $user->phone_number,
                'latest_balance' => $user->latest_balance,
                'price_alert' => optional($user->pricing)->price_alert,
                'ApiKey' => $user->ApiKey->key,
                'email_alert' => $user->email_alerts,
                'whatsapp_alert' => $user->whatsapp_alerts,
                'sms_alert' => $user->text_alerts,
            ];
        })->values();

        return response()->json(['user' => $result]);
    }
    public function export(Request $request)
    {
        $loggedInUser = Auth::user();
        $role = $request->input('role');
        $status = $request->input('status');
        $dates = $request->input('date') ? explode(',', $request->input('date')) : null;

        // $query = User::query();
        $query = User::query()
            ->select('name', 'email', 'number', 'organisation')
            ->with([
                'roles' => function ($query) {
                    $query->select('id', 'name');
                }
            ])
            ->where('id', '!=', $loggedInUser->id);
        // Check if user is Admin or not
        if (!$loggedInUser->hasRole('Admin')) {
            // Get all users under the logged-in user
            $userIds = $this->getAllReportingUserIds($loggedInUser->id);
            $query->whereIn('id', $userIds);
        }

        // Apply filters
        if ($role) {
            $query->whereHas('roles', function ($query) use ($role) {
                $query->where('name', $role);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $status);
        }

        if ($dates) {
            if (count($dates) === 1) {
                $singleDate = Carbon::parse($dates[0])->toDateString();
                $query->whereDate('created_at', $singleDate);
            } elseif (count($dates) === 2) {
                $startDate = Carbon::parse($dates[0])->startOfDay();
                $endDate = Carbon::parse($dates[1])->endOfDay();
                $query->whereBetween('created_at', [$startDate, $endDate]);
            }
        }

        $users = collect($query->get())->map(function ($user, $index) {
            return [
                'sr_no' => $index + 1,
                'name' => $user->name,
                'email' => $user->email,
                'number' => $user->number,
                'organisation' => $user->organisation,
            ];
        })->toArray();

        return Excel::download(new UserExport($users), 'users_export.xlsx');
    }

    public function getUsersByRole($role)
    {
        if ($role === 'Organizer') {
            $users = Role::where('name', 'Admin')->first()->users()->whereNull('deleted_at')->get();
        } elseif ($role === 'Agent' || $role === 'POS' || $role === 'Scanner') {
            $users = Role::where('name', 'Organizer')->first()->users()->whereNull('deleted_at')->get();
        } else {
            return response()->json(['error' => 'Invalid role'], 400);
        }

        $formattedUsers = $users->map(function ($user) {
            return [
                'value' => $user->id,
                'label' => $user->name,
                'organisation' => $user?->organisation,
            ];
        });

        return response()->json(['users' => $formattedUsers], 200);
    }

    public function destroy(string $id)
    {
        $userData = User::where('id', $id)->firstOrFail();
        if (!$userData) {
            return response()->json(['status' => false, 'message' => 'user not found'], 404);
        }

        $userData->delete();
        return response()->json(['status' => true, 'message' => 'user deleted successfully'], 200);
    }

    public function getQrLength(string $id)
    {
        $user = User::where('id', $id)->select('qr_length')->first();

        return response()->json(['status' => true, 'tokenLength' => $user->qr_length, 'message' => 'User Qr Length successfully'], 200);
    }

    public function createBulkUsers(Request $request)
    {
        try {
            $bulkInsertData = [];
            $userIds = [];
            $usersData = $request->users;

            foreach ($usersData as $user) {
                $bulkInsertData[] = [
                    'email' => $user['email'],
                    'number' => $user['number'],
                    'password' => Hash::make($user['number']), // Default password
                    'status' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Bulk insert and retrieve inserted user IDs
            User::insert($bulkInsertData);
            $insertedUsers = User::whereIn('email', array_column($usersData, 'email'))->get();

            // Assign the "User" role in bulk
            foreach ($insertedUsers as $user) {
                $user->assignRole('User');
            }

            return response()->json(['status' => true, 'message' => 'Users Created Successfully'], 201);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to create users', 'error' => $e->getMessage()], 500);
        }
    }

    private function taxStore($request, $userId)
    {
        try {

            $taxData = new BookingTax();
            $taxData->user_id = $userId;
            $taxData->convenience_fee = $request->convenience_fee;
            $taxData->type = $request->convenience_fee_type;

            $taxData->save();
            return response()->json(['status' => true, 'message' => 'taxData craete successfully', 'data' => $taxData,], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to taxData '], 404);
        }
    }

    private function taxUpdate($request, $id)
    {

        try {
            $taxData = BookingTax::firstOrNew(['user_id' => $id]);


            if ($request->has('convenience_fee')) {
                $taxData->convenience_fee = $request->convenience_fee;
            }
            if ($request->has('convenience_fee_type')) {
                $taxData->type = $request->convenience_fee_type;
            }

            $taxData->save();
            // return $taxData;
            return response()->json(['status' => true, 'message' => 'taxData update successfully', 'data' => $taxData,], 200);
        } catch (\Exception $e) {
            \Log::error('Error updating shop: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to update taxData',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    private function shopUpdate($request, $id)
    {
        try {
            $shopData = Shop::where('user_id', $id)->firstOrFail();

            if ($request->has('shop_name')) {
                $shopData->shop_name = $request->shop_name;
            }

            if ($request->has('shop_no')) {
                $shopData->shop_no = $request->shop_no;
            }

            if ($request->has('gst_no')) {
                $shopData->gst_no = $request->gst_no;
            }

            $shopData->save();
            return $shopData;
            // return response()->json(['status' => true, 'message' => 'shopData update successfully', 'shopData' => $shopData,], 200);
        } catch (\Exception $e) {
            \Log::error('Error updating shop: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to update shop data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function agentEventStore($request, $userId)
    {
        // return response()->json($request, $userId);
        try {
            // Normalize to arrays so we can store JSON in the DB
            $rawEventIds = $request->event_ids ?? $request->event_id ?? [];
            $eventIds = is_array($rawEventIds) ? $rawEventIds : [$rawEventIds];
            $eventIds = array_values(array_filter($eventIds, fn($id) => $id !== null && $id !== ''));
            $eventIds = array_map('intval', $eventIds);

            $rawTicketIds = $request->ticket_ids ?? $request->ticket_id ?? [];
            $ticketIds = is_array($rawTicketIds) ? $rawTicketIds : [$rawTicketIds];
            $ticketIds = array_values(array_filter($ticketIds, fn($id) => $id !== null && $id !== ''));
            $ticketIds = array_map('intval', $ticketIds);

            $created = AgentEvent::updateOrCreate(
                ['user_id' => $userId], // check existing record
                [
                    'event_id' => $eventIds ?: null,
                    'ticket_id' => $ticketIds ?: null,
                ]
            );

            return response()->json(['status' => true, 'message' => 'AgentEvent craete successfully', 'AgentEvents' => $created,], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 200);
        }
    }

    /**
     * Handle file uploads for user (used in both create and update methods)
     *
     * @param Request $request
     * @param User $user
     * @param string $userName User name to use for folder path
     */

    private function handleFileUploads(Request $request, User $user, string $userName): void
    {
        $fileFields = [
            'photo' => ['folder' => 'profile', 'attribute' => 'photo'],
            'doc' => ['folder' => 'document', 'attribute' => 'doc'],
            'thumbnail' => ['folder' => 'thumbnail', 'attribute' => 'thumbnail'],
        ];

        foreach ($fileFields as $inputName => $config) {
            if ($request->hasFile($inputName) && $request->file($inputName)->isValid()) {
                $folder = $config['folder'] . '/' . str_replace(' ', '_', $userName);
                $user->{$config['attribute']} = $this->storeFile($request->file($inputName), $folder);
            }
        }
    }

    /**
     * Handle organizer signature storage (draw, type, or upload)
     * Called when organizer updates profile and activity_status is false
     *
     * @param Request $request
     * @param User $user
     */
    private function handleOrganizerSignature(Request $request, User $user)
    {
        // Check if any signature data is provided (handle both field name variants)
        $hasSignatureData = $request->has('org_signature_type') ||
            $request->has('signature_type') ||
            $request->has('signature_image') ||
            $request->has('signature_text') ||
            $request->has('org_name_signatory');

        if (!$hasSignatureData) {
            return null;
        }

        // Get signature type (check both field names)
        $signatureType = $request->org_signature_type ?? $request->signature_type;

        // Build the data array based on signature type
        $data = [
            'signatory_name' => $request->org_name_signatory,
            'signature_type' => $signatureType,
            'signing_date' => $request->signing_date ?? now(),
        ];

        // ===============================
        // ✅ 1️⃣ DRAW SIGNATURE (base64)
        // ===============================
        if ($signatureType === 'draw' && $request->has('signature_image')) {
            $data['signature_image'] = $request->signature_image;
            $data['signature_text'] = null;
            $data['signature_font'] = null;
            $data['signature_font_style'] = null;
        }

        // ===============================
        // ✅ 2️⃣ TYPED SIGNATURE
        // ===============================
        if ($signatureType === 'type') {
            $data['signature_text'] = $request->signature_text;
            $data['signature_font'] = $request->signature_font;
            $data['signature_font_style'] = $request->signature_font_style;
            $data['signature_image'] = null;
        }

        // ===============================
        // ✅ 3️⃣ UPLOAD SIGNATURE (FILE)
        // ===============================
        if ($signatureType === 'upload' && $request->hasFile('signature_image')) {
            $folder = 'signatures/' . str_replace(' ', '_', $user->name);
            $data['signature_image'] = $this->storeFile($request->file('signature_image'), $folder);
            $data['signature_text'] = null;
            $data['signature_font'] = null;
            $data['signature_font_style'] = null;
        }

        // Create or update - ensures 1 entry per user
        $org_sign = OrganizerSignature::updateOrCreate(
            ['user_id' => $user->id],
            $data
        );

        // Update user's approval_request to true after signature is saved
        $user->approval_request = true;
        $user->save();
    }


    public function eventTicket($eventId)
    {
        try {
            // Get event data
            if (is_numeric($eventId)) {
                // integer → find by id
                $event = Event::with('tickets')->where('id', $eventId)->first();
            } else {
                // not integer → find by event_key
                $event = Event::with('tickets')->where('event_key', $eventId)->first();
            }

            if (!$event) {
                return response()->json([
                    'status' => false,
                    'message' => 'Event not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'event' => [
                    'id' => $event->id,
                    'name' => $event->name,
                ],
                'tickets' => $event->tickets->map(function ($ticket) {
                    return [
                        'id' => $ticket->id,
                        'name' => $ticket->name,
                        'price' => $ticket->price,
                    ];
                })
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function userTicketStore($request, $userId)
    {
        try {
            $tickets = $request->tickets;

            if (!isset($tickets) || !is_array($tickets) || empty($tickets)) {
                return response()->json(['status' => false, 'message' => 'No tickets provided'], 400);
            }

            $grouped = collect($tickets)->groupBy('eventId');

            foreach ($grouped as $eventId => $ticketGroup) {
                $ticketIds = [];
                foreach ($ticketGroup as $ticket) {
                    if (isset($ticket['value'])) {
                        $ticketIds[] = $ticket['value'];
                    }
                }

                if (empty($ticketIds)) {
                    continue;
                }

                $userTicket = UserTicket::updateOrCreate(
                    [
                        'user_id' => $userId,
                        'event_id' => $eventId,
                    ],
                    [
                        'ticket_id' => $ticketIds,
                    ]
                );
            }

            return response()->json(['status' => true, 'message' => 'Tickets stored successfully', 'data' => $userTicket], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to store tickets: ' . $e->getMessage()
            ], 500);
        }
    }

    public function organizerList()
    {
        $organizers = User::whereHas('roles', function ($query) {
            $query->where('name', 'Organizer');
        })->select('id', 'name', 'email', 'number')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Organizer list fetched successfully.',
            'data' => $organizers
        ], 200);
    }

    public function getOrganizers()
    {
        $organizers = User::whereHas('roles', function ($query) {
            $query->where('name', 'Organizer');
        })->select('id', 'name', 'organisation')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Organizers retrieved successfully.',
            'data' => $organizers
        ], 200);
    }

    public function oneClickLogin(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'auth_session' => 'required|string',
            'user_id' => 'required|exists:users,id',
        ]);

        try {
            $impersonator = auth()->user();

            if (!$impersonator || !$impersonator->hasPermissionTo('Impersonet')) {
                return response()->json([
                    'status' => false,
                    'error' => 'Unauthorized access. You need Impersonet permission.'
                ], 403);
            }

            $encryptedSessionId = Crypt::encryptString($request->session_id);
            $encryptedAuthSession = Crypt::encryptString($request->auth_session);

            $targetUser = User::findOrFail($request->user_id);

            // Store impersonator info in cache for 60 minutes
            $cacheKey = 'impersonator_' . $encryptedSessionId;
            Cache::put($cacheKey, $impersonator->id, now()->addMinutes(60));

            if (!Cache::has($cacheKey)) {
                return response()->json([
                    'status' => false,
                    'error' => 'Impersonator was not saved in cache.'
                ], 500);
            }

            $token = $targetUser->createToken('one-click-login')->accessToken;

            return response()->json([
                'status' => true,
                'token' => $token,
                'user' => $this->formatUserResponse($targetUser),
                'session_id' => $encryptedSessionId,
                'auth_session' => $encryptedAuthSession,
                'message' => 'Logged in using one-click login.'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => 'Something went wrong.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function revertImpersonation(Request $request)
    {
        $cacheKey = 'impersonator_' . $request->session_id;

        if (!Cache::has($cacheKey)) {
            return response()->json([
                'status' => false,
                'error' => 'Session ID not found in cache.',
                'debug' => ['key' => $cacheKey]
            ], 400);
        }

        $impersonatorId = Cache::pull($cacheKey);
        $originalUser = User::find($impersonatorId);

        if (!$originalUser) {
            return response()->json([
                'status' => false,
                'error' => 'Original user not found.'
            ], 404);
        }

        $token = $originalUser->createToken('revert-login')->accessToken;

        return response()->json([
            'status' => true,
            'token' => $token,
            'user' => $this->formatUserResponse($originalUser),
            // 'session_id' => $request->session_id,
            // 'auth_session' => $request->auth_session,
            'message' => 'Reverted back to original user.'
        ]);
    }

    private function formatUserResponse(User $user): array
    {
        $role = $user->roles->first();
        $rolePermissions = $role ? $role->permissions : collect();
        $userPermissions = $user->permissions ?? collect();

        $allPermissions = $rolePermissions->merge($userPermissions)->unique('name');
        $permissionNames = $allPermissions->pluck('name');

        $userArray = $user->toArray();
        $userArray['role'] = $role ? $role->name : null;
        $userArray['permissions'] = $permissionNames;

        return $userArray;
    }


    public function downloadAgreement($id)
    {
        $user = User::findOrFail($id);

        $data = $this->prepareAgreementData($user);

        $pdf = Pdf::loadView('agreements.org-agreement', $data)
            ->setPaper('a4');

        return $pdf->download("Organizer_Agreement_{$user->id}.pdf");
    }

    private function prepareAgreementData($user)
    {
        // Get signature data from the new table
        $signature = $user->organizerSignature;

        return [
            'signing_date' => $signature?->signing_date?->format('d/m/Y') ?? now()->format('d/m/Y'),
            'org_signatory' => $signature?->signatory_name ?? 'Authorized Person',
            'org_name' => $user->name ?? 'Organizer Pvt. Ltd.',
            'org_type' => $user->orgType?->title ?? 'Private Limited',
            'org_reg_address' => $user->org_office_address ?? 'Not Available',
            'gst' => $user->org_gst_no ?? 'N/A',
            'pan' => $user->pan_no ?? 'N/A',
            'bank_beneficiary' => $user->bank_beneficiary ?? 'N/A',
            'bank_account' => $user->bank_account ?? 'N/A',
            'bank_ifsc' => $user->bank_ifsc ?? 'N/A',
            'bank_name' => $user->bank_name ?? 'N/A',
            'bank_branch' => $user->bank_branch ?? 'N/A',
            'event_name' => $user->event_name ?? 'Sample Event',
            'event_venue' => $user->event_venue ?? 'Sample Venue',
            'event_dates' => $user->event_dates ?? '01-03 Oct 2025',
            'commission_percent' => $user->commission_percent ?? 3,
            'payment_terms' => $user->payment_terms ?? 'Within 10 days after event',
            'term_text' => $user->term_text ?? '12 months',
            'notice_to_name' => 'Janak Rana',
            'notice_to_email' => 'janak@getyourticket.in',
            'notice_to_address' => '401, BLUE CRYSTAL COM, Vallabh Vidyanagar, Anand, Gujarat 388120',
            'show_watermark' => true,
            'signature_type' => $signature?->signature_type,
            'signature_image' => $signature?->signature_image,
            'signature_text' => $signature?->signature_text,
            'signature_font' => $signature?->signature_font,
        ];
    }

    private function storeFile($file, $folder, $disk = 'public')
    {
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
        return Storage::disk($disk)->url($path);
    }
    public function sendRegisterMail($user)
    {
        try {
            $verificationUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60), // Link expires in 60 minutes
                [
                    'id' => $user->id,
                    'hash' => sha1($user->getEmailForVerification())
                ]
            );

            // Get email template from database
            $emailTemplate = EmailTemplate::where('template_id', 'User Verification')->first();

            if (!$emailTemplate) {
                Log::error('Email template "User Verification" not found');
                return;
            }

            // Replace placeholders in template body
            $body = str_replace(
                ['{{username}}', '{{verification_link}}'],
                [$user->name, $verificationUrl],
                $emailTemplate->body
            );

            // Dispatch email job directly
            $details = [
                'email' => $user->email,
                'title' => $emailTemplate->subject,
                'body' => $body,
            ];

            dispatch(new SendEmailJob($details));

            Log::info('Verification email queued for: ' . $user->email);
        } catch (\Exception $e) {
            Log::error('Failed to queue verification email: ' . $e->getMessage());
        }
    }
    private function sendIntimation($request, $user, $smsService)
    {
        if ($request->role_name == 'Organizer') {
            $buttonValue = User::where('id', $request->user_id)->first();
            $filename = $buttonValue ? basename($buttonValue->card_url) : null;

            $whatsappTemplate = WhatsappApi::where('title', 'Acc Ready')->first();
            $whatsappTemplateName = $whatsappTemplate->template_name ?? '';

            $admin = User::role('Admin', 'api')->first();
            $organizer = $user;

            if ($admin) {
                $adminData = (object) [
                    'name' => $admin->name,
                    'number' => $admin->number,
                    'templateName' => 'new registatiion admin reminder',
                    'replacements' => [
                        ':O_Name' => $organizer->name,
                        ':O_number' => $organizer->number,
                        ':C_Email' => $organizer->email,
                        ':C_Registered' => $organizer->id,
                    ]
                ];

                $smsService->send($adminData);
            }

            // === Send to ORGANIZER ===
            if ($organizer) {
                $allowedDomain = rtrim(env('ALLOWED_DOMAIN', 'https://ssgarba.com/'), '/');
                $organizerData = (object) [
                    'name' => $organizer->name,
                    'number' => $organizer->number,
                    'templateName' => 'organizer registration',
                    'replacements' => [
                        ':S_Link' => $allowedDomain . '/',
                        // ':S_Link'     => 'https://getyourticket.in/',
                        ':C_number' => $admin->number,
                    ]
                ];

                $smsService->send($organizerData);
            }
        }
    }
}
