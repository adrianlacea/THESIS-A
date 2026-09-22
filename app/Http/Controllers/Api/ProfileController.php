<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Core\ActivityLog;
use App\Models\Core\SystemNotification;
use App\Models\Customer\Customer;
use App\Models\Hr\Employee;
use App\Mail\OtpVerificationMail;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $employee = Employee::with('user', 'user.branch')
            ->where('user_id', $user->id)
            ->where('store_id', $user->store_id)
            ->first();
        $customer = Customer::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
        $customerProfile = $this->customerProfileData($customer, (int) $user->id);
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'employee' => $employee,
                'customer' => $customerProfile,
                'verification_documents' => [],
            ]
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'fname' => 'sometimes|string|max:100',
            'lname' => 'sometimes|string|max:100',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'birthday' => 'nullable|date',
            'contact_number' => 'nullable|string|max:30',
            'phone_number' => 'nullable|string|max:30',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'barangay' => 'nullable|string|max:150',
            'gender' => 'nullable|in:male,female,other',
            'emergency_contact_name' => 'nullable|string|max:120',
            'emergency_contact_phone' => 'nullable|string|max:30',
            'emergency_contact_relationship' => 'nullable|string|max:60',
        ]);

        $contactNumberProvided = false;
        $contactNumber = null;
        foreach (['phone_number', 'contact_number', 'phone'] as $contactField) {
            if (array_key_exists($contactField, $validated)) {
                $contactNumberProvided = true;
                $contactNumber = $validated[$contactField];
                break;
            }
        }

        $user->fill([
            'fname' => $validated['fname'] ?? $user->fname,
            'lname' => $validated['lname'] ?? $user->lname,
            'email' => $validated['email'] ?? $user->email,
            'birthday' => $validated['birthday'] ?? $user->birthday,
            ...($contactNumberProvided ? ['phone_number' => $contactNumber] : []),
        ]);

        $requiresVerification = false;
        if (array_key_exists('email', $validated) && $user->isDirty('email')) {
            $user->email_verified_at = null;
            $requiresVerification = true;
        }

        $user->save();

        if ($contactNumberProvided) {
            $latestCustomer = Customer::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->first();

            if (!$latestCustomer && $contactNumber !== null && $contactNumber !== '') {
                $latestCustomer = Customer::query()->create([
                    'user_id' => $user->id,
                    'verification_status' => 'unverified',
                ]);
            }

            $latestCustomer?->update([
                'contact_number' => $contactNumber,
            ]);
        }

        if ($requiresVerification) {
            $otp = $user->generateOtp();
            Mail::to($user->email)->send(new OtpVerificationMail($otp, $user->fname));
        }

        $employee = Employee::where('user_id', $user->id)->first();
        if ($employee) {
            $employee->fill([
                'fname' => $validated['fname'] ?? $employee->fname,
                'lname' => $validated['lname'] ?? $employee->lname,
                'address' => $validated['address'] ?? $employee->address,
                'province' => $validated['province'] ?? $employee->province,
                'city' => $validated['city'] ?? $employee->city,
                'barangay' => $validated['barangay'] ?? $employee->barangay,
                'date_of_birth' => $validated['birthday'] ?? $employee->date_of_birth,
                'gender' => $validated['gender'] ?? $employee->gender,
                'emergency_contact_name' => $validated['emergency_contact_name'] ?? $employee->emergency_contact_name,
                'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? $employee->emergency_contact_phone,
                'emergency_contact_relationship' => $validated['emergency_contact_relationship'] ?? $employee->emergency_contact_relationship,
            ]);
            $employee->save();
        }

        ActivityLog::record(
            'profile.update',
            'Updated profile information',
            ['updated_fields' => array_keys($validated)],
            'User',
            $user->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'requires_verification' => $requiresVerification,
            'data' => [
                'user' => $user->fresh(),
                'employee' => $employee?->fresh(),
                'customer' => $this->customerProfileData(
                    Customer::query()->where('user_id', $user->id)->latest('id')->first(),
                    (int) $user->id
                ),
                'verification_documents' => [],
            ]
        ]);
    }

    private function customerProfileData(?Customer $customer, int $userId): array
    {
        $profile = $customer?->toArray() ?? [
            'user_id' => $userId,
            'contact_number' => null,
        ];

        $decision = SystemNotification::query()
            ->where('user_id', $userId)
            ->where('module', 'ecommerce')
            ->where('entity_type', 'customer_verification')
            ->latest('created_at')
            ->first(['action', 'title', 'message', 'created_at']);

        $action = strtolower((string) ($decision?->action ?? ''));
        $title = strtolower((string) ($decision?->title ?? ''));
        $status = match (true) {
            $action === 'approved' || str_contains($title, 'approved') => 'approved',
            $action === 'rejected' || str_contains($title, 'rejected') => 'rejected',
            $action === 'pending' || str_contains($title, 'pending') => 'pending',
            default => 'unverified',
        };

        $profile['verification_status'] = $status;
        $profile['verification_reviewed_at'] = $status === 'approved' ? $decision?->created_at : null;
        $profile['verification_message'] = $decision?->message;

        return $profile;
    }

    public function updateAvatar(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Avatar upload not implemented'
        ], 501);
    }

    public function removeAvatar(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Avatar removal not implemented'
        ], 501);
    }

}
