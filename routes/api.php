<?php

use App\Http\Controllers\Api\Admin\RolePermissionController;
use App\Http\Controllers\Api\Store\StoreVerificationController;

use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Api\ProfileController as ApiProfileController;
use App\Http\Controllers\Api\Store\StoreController;
use App\Http\Controllers\Api\Store\BranchController;
use App\Http\Controllers\Api\Store\StoreSettingsController;
use App\Http\Controllers\Api\Store\StoreDashboardController;
use App\Http\Controllers\Api\Store\StoreModuleController;

use App\Http\Controllers\Api\Hr\EmployeeController;
use App\Http\Controllers\Api\Hr\PayPeriodController;
use App\Http\Controllers\Api\Hr\DeductionTypeController;
use App\Http\Controllers\Api\Hr\PayrollController;
use App\Http\Controllers\Api\Hr\DepartmentController;
use App\Http\Controllers\Api\UserNavigationController;
use App\Http\Controllers\Api\Store\RoleController as StoreRoleController;
use App\Http\Controllers\Api\Store\StoreScopedRoleController;
use App\Http\Controllers\Api\Payments\PaymongoController;
use App\Http\Controllers\Api\Admin\CustomerManagementController;
use App\Http\Controllers\Api\Admin\HomepageContentController;
use App\Http\Controllers\Api\Admin\EcommerceCategoryController;
use App\Http\Controllers\Api\Admin\SubscriptionManagementController;
use App\Http\Controllers\Api\Admin\SubscriptionPlanController;
use App\Http\Controllers\Api\Admin\StoreManagementController;
use App\Http\Controllers\Api\Admin\SupplierVerificationController;
use App\Http\Controllers\Api\Admin\ViolationReportController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\Core\SystemNotificationController;
use App\Http\Controllers\Api\Ecommerce\EcommerceActiveStockProductsController;
use App\Http\Controllers\Api\Ecommerce\EcommerceController;
use App\Http\Controllers\Api\ProductCatalog\ProductAssetController;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;


// ========== PUBLIC ROUTES ==========
Route::prefix('auth')->group(function () {
    Route::middleware('throttle:login')->post('login', [AuthController::class, 'login']);
    Route::middleware('throttle:login')->post('super-admin/login', [AuthController::class, 'superAdminLogin']);
    Route::middleware('throttle:login-with-clock-in')->post('login-with-clock-in', [AuthController::class, 'loginWithClockIn']);
    Route::middleware('throttle:register')->post('register', [AuthController::class, 'register']);
    Route::middleware('throttle:register')->post('supplier/register', [AuthController::class, 'registerSupplier']);
    Route::middleware('throttle:password-reset')->post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::middleware('throttle:password-reset')->post('reset-password', [AuthController::class, 'resetPassword']);

    // Email Verification (public routes with temporary token)
    Route::post('verify-otp', [VerifyEmailController::class, 'verifyOtpApi']);
    Route::post('resend-otp', [VerifyEmailController::class, 'resendOtpApi']);
});

Route::get('/public/home-content', [HomepageContentController::class, 'publicIndex']);

require __DIR__ . '/job_portal_routes.php';

// Public ecommerce browsing (guest-friendly)
Route::prefix('ecommerce')->group(function () {
    Route::get('/products', [EcommerceController::class, 'products']);
    Route::get('/products/active-stock', [EcommerceActiveStockProductsController::class, 'index']);
    Route::get('/categories/active-stock', [EcommerceActiveStockProductsController::class, 'categories']);
    Route::get('/categories/{categoryId}/top-stores', [EcommerceActiveStockProductsController::class, 'topStores']);
    Route::get('/reviews/{review}/attachment', [EcommerceController::class, 'reviewAttachment']);
    Route::get('/products/{id}', [EcommerceController::class, 'productShow']);
    Route::get('/stores', [EcommerceController::class, 'storeDirectory']);
    Route::get('/stores/{storeId}', [EcommerceController::class, 'storeProfile']);
    Route::get('/stores/{storeId}/products', [EcommerceController::class, 'storeProducts']);
    Route::get('/stores/{storeId}/reviews', [EcommerceController::class, 'storeReviews']);
    Route::post('/dss/recommendations', [EcommerceController::class, 'dssRecommendations']);
    Route::get('/dss/trending-movement', [EcommerceController::class, 'dssTrendingByMovement']);
});

// Public 3D/image asset serve route (must stay outside auth middleware)
Route::get('/product-catalog/assets/{id}/serve', [ProductAssetController::class, 'serve']);
Route::post('/payments/paymongo/webhook', [PaymongoController::class, 'webhook']);
Route::get('/public/subscription-plans', [SubscriptionPlanController::class, 'publicIndex']);
Route::get('/public/subscription-plans/{planKey}/modules', [SubscriptionPlanController::class, 'publicModules']);

// ========== PROTECTED ROUTES ==========

Route::prefix('locations')->group(function () {
    Route::get('/provinces', [\App\Http\Controllers\Api\Location\PSGCController::class, 'provinces']);
    Route::get('/cities', [\App\Http\Controllers\Api\Location\PSGCController::class, 'cities']);
    Route::get('/barangays', [\App\Http\Controllers\Api\Location\PSGCController::class, 'barangays']);
});

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/user/navigation', [UserNavigationController::class, 'getUserNavigation']);
    Route::post('/user/check-permission', [UserNavigationController::class, 'checkPermission']);
    Route::get('/user/debug-permissions', [UserNavigationController::class, 'debugPermissions']);
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    // Add admin supplier verification endpoints
    Route::get('/admin/suppliers/pending', [SupplierVerificationController::class, 'index']);
    Route::post('/admin/suppliers/{id}/approve', [SupplierVerificationController::class, 'approve']);
    Route::post('/admin/suppliers/{id}/reject', [SupplierVerificationController::class, 'reject']);
    Route::get('/admin/suppliers/{id}', [SupplierVerificationController::class, 'show']);
    Route::post('/admin/suppliers/{id}/request-resubmission', [SupplierVerificationController::class, 'requestResubmission']);
    Route::get('/violation-reports/my', [ViolationReportController::class, 'my']);
    Route::get('/violation-reports/{id}/responses', [ViolationReportController::class, 'responses']);
    Route::post('/violation-reports/{id}/responses', [ViolationReportController::class, 'addResponse']);
    Route::prefix('notifications')->group(function () {
        Route::get('/', [SystemNotificationController::class, 'index']);
        Route::get('/unread', [SystemNotificationController::class, 'getUnread']);
        Route::get('/{id}', [SystemNotificationController::class, 'show']);
        Route::put('/{id}/read', [SystemNotificationController::class, 'markAsRead']);
        Route::put('/mark-all-read', [SystemNotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [SystemNotificationController::class, 'delete']);
        Route::post('/batch-delete', [SystemNotificationController::class, 'batchDelete']);
    });

    Route::prefix('admin')->group(function () {
        Route::get('/roles', [RolePermissionController::class, 'getRoles']);
        Route::get('/roles/primary', [RolePermissionController::class, 'primaryRolesAdmin']);
        Route::delete('/roles/{id}', [RolePermissionController::class, 'deleteRole']);
        Route::get('/roles/{id}/permissions', [RolePermissionController::class, 'getRolePermissions']);
        Route::post('/roles/{id}/permissions', [RolePermissionController::class, 'updateRolePermissions']);
        Route::get('/roles/export', [RolePermissionController::class, 'exportRoles']);
        // Admin dashboard data
        Route::get('/dashboard', [\App\Http\Controllers\Api\Admin\DashboardController::class, 'index']);
        Route::post('/roles/import', [RolePermissionController::class, 'importRoles']);

        // Permissions
        Route::get('/permissions', [RolePermissionController::class, 'getPermissions']);
        Route::post('/permissions', [RolePermissionController::class, 'createPermission']);
        Route::put('/permissions/{id}', [RolePermissionController::class, 'updatePermission']);
        Route::delete('/permissions/{id}', [RolePermissionController::class, 'deletePermission']);
        Route::get('/permissions/export', [RolePermissionController::class, 'exportPermissions']);
        Route::post('/permissions/import', [RolePermissionController::class, 'importPermissions']);

        // Master module catalog used by permissions and navigation.
        Route::get('/modules', [RolePermissionController::class, 'getModules']);
        Route::post('/modules', [RolePermissionController::class, 'createModule']);
        Route::put('/modules/{id}', [RolePermissionController::class, 'updateModule']);

        // Navigation Items
        Route::get('/navigation-items', [RolePermissionController::class, 'getNavigationItems']);
        Route::post('/navigation-items', [RolePermissionController::class, 'createNavigationItem']);
        Route::put('/navigation-items/{id}', [RolePermissionController::class, 'updateNavigationItem']);
        Route::delete('/navigation-items/{id}', [RolePermissionController::class, 'deleteNavigationItem']);
        // Store Modules (super admin override)
        Route::get('/store-modules/stores', [\App\Http\Controllers\Api\Admin\StoreModuleController::class, 'stores']);
        Route::get('/store-modules/modules', [\App\Http\Controllers\Api\Admin\StoreModuleController::class, 'modules']);
        Route::get('/store-modules', [\App\Http\Controllers\Api\Admin\StoreModuleController::class, 'index']);
        Route::post('/store-modules/override', [\App\Http\Controllers\Api\Admin\StoreModuleController::class, 'override']);

        // Customer Validation

        // Customer Management
        Route::get('/customers', [CustomerManagementController::class, 'index']);
        Route::get('/home-content', [HomepageContentController::class, 'index']);
        Route::post('/home-content/modules', [HomepageContentController::class, 'storeModule']);
        Route::post('/home-content/modules/{module}', [HomepageContentController::class, 'updateModule']);
        Route::delete('/home-content/modules/{module}', [HomepageContentController::class, 'destroyModule']);
        Route::post('/home-content/furniture/{furniture}', [HomepageContentController::class, 'updateFurniture']);
        Route::get('/ecommerce-categories', [EcommerceCategoryController::class, 'index']);
        Route::post('/ecommerce-categories/{category}', [EcommerceCategoryController::class, 'update']);
        Route::get('/subscriptions', [SubscriptionManagementController::class, 'index']);
        Route::get('/subscriptions/stats', [SubscriptionManagementController::class, 'stats']);
        Route::put('/subscriptions/{store}', [SubscriptionManagementController::class, 'update']);
        Route::post('/subscriptions/{store}/extend', [SubscriptionManagementController::class, 'extend']);
        Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
        Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store']);
        Route::get('/subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'show']);
        Route::put('/subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'update']);
        Route::delete('/subscription-plans/{subscriptionPlan}', [SubscriptionPlanController::class, 'destroy']);


        // Super Admin Management
        Route::get('/super-admins', [\App\Http\Controllers\Api\Admin\SuperAdminManagementController::class, 'index']);
        Route::post('/super-admins', [\App\Http\Controllers\Api\Admin\SuperAdminManagementController::class, 'store']);

        // Store Management
        Route::get('/stores', [StoreManagementController::class, 'index']);
        Route::get('/stores/{store}', [StoreManagementController::class, 'show']);
        Route::patch('/stores/{store}/deactivate', [StoreManagementController::class, 'deactivate']);
        Route::delete('/stores/{store}', [StoreManagementController::class, 'destroy']);
        Route::get('/users', [StoreManagementController::class, 'usersIndex']);

        // Violation Reports
        Route::middleware('role:super_admin')->group(function () {
            Route::get('/violation-reports', [ViolationReportController::class, 'index']);
            Route::get('/violation-reports/{violationReport}', [ViolationReportController::class, 'show']);
            Route::post('/violation-reports/{violationReport}/suspend', [ViolationReportController::class, 'suspend']);
            Route::post('/violation-reports/{violationReport}/ban', [ViolationReportController::class, 'ban']);
        });
});

    // ========== STORE ROLES & PERMISSIONS ==========
    Route::prefix('store')->group(function () {
        Route::get('/dashboard', [StoreDashboardController::class, 'index']);
        Route::get('/settings', [StoreSettingsController::class, 'show']);
        Route::put('/settings/profile', [StoreSettingsController::class, 'updateProfile']);
        Route::put('/settings/modules', [StoreSettingsController::class, 'updateModules']);
        Route::put('/settings/payments', [StoreSettingsController::class, 'updatePaymentSettings']);
        Route::get('/settings/hr', [StoreSettingsController::class, 'showHrSettings']);
        Route::put('/settings/hr', [StoreSettingsController::class, 'updateHrSettings']);
        Route::put('/settings/attendance', [StoreSettingsController::class, 'updateAttendanceSettings']);
        Route::get('/roles/scoped', [StoreScopedRoleController::class, 'index']);
        Route::get('/roles/scoped-by-department', [StoreScopedRoleController::class, 'scopedByDepartment']);
        Route::get('/roles', [StoreRoleController::class, 'index']);
        Route::get('/roles/store-specific', [StoreRoleController::class, 'storeSpecific']);
        Route::post('/roles', [StoreRoleController::class, 'store']);
        Route::put('/roles/{id}', [StoreRoleController::class, 'update']);
        Route::delete('/roles/{id}', [StoreRoleController::class, 'destroy']);
        Route::get('/permissions', [StoreRoleController::class, 'getPermissions']);
        Route::get('/modules', [StoreRoleController::class, 'getModules']);
        Route::put('/modules', [StoreRoleController::class, 'updateModules']);
        Route::get('/roles/{id}/permissions', [StoreRoleController::class, 'getRolePermissions']);
        Route::post('/roles/{id}/permissions', [StoreRoleController::class, 'updateRolePermissions']);
    });

    // Branch-scoped Store module
    Route::prefix('store-module')->controller(StoreModuleController::class)->group(function () {
        Route::get('/dashboard', 'dashboard');
        Route::get('/settings', 'settings');
        Route::match(['put', 'post'], '/settings', 'updateSettings');
        Route::get('/ecommerce', 'ecommerce');
    });

    Route::prefix('payments')->group(function () {
        Route::post('paymongo/create', [PaymongoController::class, 'create']);
        Route::post('paymongo/checkout-session', [PaymongoController::class, 'createCheckoutSession']);
        Route::get('paymongo/checkout-session/{id}', [PaymongoController::class, 'retrieveCheckoutSession']);
        Route::get('paymongo/public-key', [PaymongoController::class, 'publicKey']);
        Route::get('paymongo/latest', [PaymongoController::class, 'latestByPayable']);
        Route::get('paymongo/{id}', [PaymongoController::class, 'status']);
        Route::post('paymongo/{id}/gcash-start', [PaymongoController::class, 'startGcash']);
        Route::post('paymongo/{id}/wallet/{type}/start', [PaymongoController::class, 'startWallet']);
        Route::post('paymongo/{id}/card-start', [PaymongoController::class, 'startCard']);
    });



    // ========== AUTHENTICATION ==========
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('logout-with-clock-out', [AuthController::class, 'logoutWithClockOut'])
            ->withoutMiddleware([EnsureFrontendRequestsAreStateful::class]);
        Route::post('logout-no-csrf', [AuthController::class, 'logout'])
            ->withoutMiddleware([EnsureFrontendRequestsAreStateful::class]);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('change-password', [AuthController::class, 'changePassword']);

        // User info
        Route::get('user', fn(Request $request) => response()->json([
            'success' => true,
            'user' => $request->user(),
            'email_verified' => $request->user()->hasVerifiedEmail()
        ]));
    });

    // ========== PROFILE ==========
    Route::prefix('profile')->controller(ApiProfileController::class)->group(function () {
        Route::get('/', 'show');
        Route::put('/', 'update');
        Route::post('avatar', 'updateAvatar');
        Route::delete('avatar', 'removeAvatar');
    });

    // ========== USER MANAGEMENT ==========
    Route::apiResource('users', UserController::class);

    Route::prefix('users')->group(function () {});

    // =========== HR ==============
    Route::post('/employees/invite', [EmployeeController::class, 'storeInvite']);
    Route::get('/employees/me', [EmployeeController::class, 'me']);
    Route::apiResource('employees', EmployeeController::class);
    Route::get('/employees/{id}/details', [EmployeeController::class, 'getEmployeeDetails']);
    Route::get('/employees/{id}/details/{year}', [EmployeeController::class, 'getEmployeeDetails']);
    Route::post('/employees/id-preview', [EmployeeController::class, 'previewGovernmentId']);
    Route::post('/employees/{employeeId}/government-ids/{governmentIdId}/verify', [EmployeeController::class, 'verifyGovernmentId']);
    Route::put('/employees/{employeeId}/weekly-schedule', [EmployeeController::class, 'saveWeeklySchedule']);

    // Simplified employee summary (for dashboard/widgets)
    Route::get('/employees/{id}/summary', [EmployeeController::class, 'getEmployeeSummary']);

    // Optional: With year filter
    Route::get('/employees/{id}/details/{year}', [EmployeeController::class, 'getEmployeeDetails']);
    // Route::get('roles')


    // Departments
    Route::apiResource('departments', DepartmentController::class);
    Route::get('departments-statistics', [DepartmentController::class, 'statistics']);
    Route::get('departments-options', [DepartmentController::class, 'options']);
    Route::post('departments-bulk-destroy', [DepartmentController::class, 'bulkDestroy']);


    Route::prefix('payroll')->group(function () {
        // Pay Periods
        Route::prefix('periods')->group(function () {
            Route::get('/', [PayPeriodController::class, 'index']);
            Route::post('/', [PayPeriodController::class, 'store']);
            Route::put('/{id}', [PayPeriodController::class, 'update']);
            Route::delete('/{id}', [PayPeriodController::class, 'destroy']);
            Route::post('/{id}/close', [PayPeriodController::class, 'close']);
        });

        // Payroll Overview
        Route::get('/overview', [PayrollController::class, 'overview']);
        Route::get('/preview', [PayrollController::class, 'preview']);

        // Payroll
        Route::get('/pay-periods', [PayPeriodController::class, 'getAllPayPeriods']);
        Route::get('/pay-periods/{id}/payroll', [PayPeriodController::class, 'getPayrollPerPeriod']);
        Route::get('/pay-periods/{id}/export', [PayPeriodController::class, 'exportPayrollPeriod']);
        Route::get('/pay-periods/{id}', [PayPeriodController::class, 'show']);

        Route::post('/generate', [PayrollController::class, 'generate']);
        Route::post('/bulk-submit', [PayrollController::class, 'bulkSubmitForApproval']);
        Route::post('/bulk-approve', [PayrollController::class, 'bulkApprove']);
        Route::post('/bulk-mark-paid', [PayrollController::class, 'bulkMarkPaid']);
        Route::get('/payslip/{employeeId}', [PayrollController::class, 'getEmployeePayslips']);
        Route::get('/payslip/{employeeId}/rundown/print', [PayrollController::class, 'printEmployeePayrollRundown']);
        Route::get('/{id}/payslip/pdf', [PayrollController::class, 'downloadPayslipPdf']);
        Route::get('/{id}/payslip/print', [PayrollController::class, 'printPayslip']);

        Route::get('/', [PayrollController::class, 'index']);
        Route::get('/getEmployeesBasicSalary', [PayrollController::class, 'getEmployeeBasicSalary']);
        Route::get('/report/summary', [PayrollController::class, 'report']);
        Route::post('/calculate', [PayrollController::class, 'testCalculatePayroll']);
        Route::get('/{id}', [PayrollController::class, 'show']);
        Route::put('/{id}', [PayrollController::class, 'update']);
        Route::post('/{id}/submit', [PayrollController::class, 'submit']);
        Route::post('/{id}/approve', [PayrollController::class, 'approve']);
        Route::post('/{id}/release', [PayrollController::class, 'release']);
        Route::post('/{id}/mark-paid', [PayrollController::class, 'markPaid']);
    });

    // Payslip PDF aliases (legacy frontend usage)
    Route::get('/payrolls/{id}/payslip/pdf', [PayrollController::class, 'downloadPayslipPdf']);
    Route::get('/payrolls/{id}/payslip/print', [PayrollController::class, 'printPayslip']);

    Route::prefix('hr/dashboard')->controller(\App\Http\Controllers\Api\Hr\DashboardController::class)->group(function () {
        Route::get('/analytics-overview', 'getAnalyticsOverview');
        Route::get('/today-stats', 'getTodayStats');
        Route::get('/weekly-attendance', 'getWeeklyAttendance');
        Route::get('/monthly-summary', 'getMonthlySummary');
    });

    // Deductions
    Route::prefix('deductions')->group(function () {
        Route::get('/deduction-types', [DeductionTypeController::class, 'index']);
        Route::post('/deduction-types', [DeductionTypeController::class, 'store']);
        Route::get('/deduction-types/{id}', [DeductionTypeController::class, 'show']);
        Route::put('/deduction-types/{id}', [DeductionTypeController::class, 'update']);
        Route::delete('/deduction-types/{id}', [DeductionTypeController::class, 'destroy']);
        Route::post('/deduction-types/{id}/toggle-active', [DeductionTypeController::class, 'toggleActive']);
        Route::get('/deduction-types/by-category', [DeductionTypeController::class, 'getByCategory']);
    });


    // ========== STORE MANAGEMENT ==========
    Route::get('pending-verification', [StoreVerificationController::class, 'getPendingVerifications']);
    Route::get('store-verifications', [StoreVerificationController::class, 'index']);
    Route::post('store-verification/owner-id/extract', [StoreVerificationController::class, 'extractOwnerId']);
    Route::post('store-verification/{verification}/review', [StoreVerificationController::class, 'reviewVerification']);
    Route::get('store-verification/{verification}/documents/{document}/inspect', [StoreVerificationController::class, 'inspectDocument']);
    Route::get('store-verification/{verification}/documents/{document}/download', [StoreVerificationController::class, 'downloadDocument']);
    Route::post('store-verification/{verification}/documents/{document}/auto-validate', [StoreVerificationController::class, 'autoValidateDocument']);
    Route::post('store-verification/{verification}/documents/auto-validate-all', [StoreVerificationController::class, 'autoValidateAllDocuments']);
    Route::prefix('stores')->controller(StoreController::class)->group(function () {
        Route::get('hasStore', 'hasStore');
        Route::post('register', 'store'); // Store registration

        // Specific Store Operations
        Route::prefix('{store}')->group(function () {
            Route::get('/', 'show');
            Route::delete('/', 'destroy');
            Route::put('/', 'update');
            Route::put('/subscription', 'updateSubscription');

            // Store Verification
            Route::prefix('verification')->group(function () {
                Route::post('submit', [StoreVerificationController::class, 'submitDocuments']);
                Route::get('status', [StoreVerificationController::class, 'getStatus']);
                Route::get('documents', [StoreVerificationController::class, 'getDocuments']);
            });



            // Store Assignment
            Route::post('assign', [UserController::class, 'assignToStore'])->middleware('role:admin');
        });
    });

    // Store Branches
    Route::prefix('branches')->controller(BranchController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('{branch}', 'show');
        Route::put('{branch}', 'update');
        Route::delete('{branch}', 'destroy');
    });

    require __DIR__ . '/attendance_routes.php';
    require __DIR__ . '/product_routes.php';
    require __DIR__ . '/procurement_routes.php';
    require __DIR__ . '/supplier_routes.php';
    require __DIR__ . '/supplier_portal_routes.php';
    // expose supplier_portals for admin UI merging (optional)
    Route::get('/supplier-portals', [\App\Http\Controllers\Api\SupplierPortalsController::class, 'index']);
    Route::get('/supplier-portals/{id}', [\App\Http\Controllers\Api\SupplierPortalsController::class, 'show']);
    require __DIR__ . '/inventory_routes.php';
    require __DIR__ . '/warehouse_routes.php';
    require __DIR__ . '/logistics_routes.php';
    require __DIR__ . '/ecommerce_routes.php';
    require __DIR__ . '/sales_routes.php';
    require __DIR__ . '/crm_routes.php';
    require __DIR__ . '/job_hiring_routes.php';
    require __DIR__ . '/finance_routes.php';

    // ========== TEST ROUTES (Remove in production) ==========
    Route::prefix('test')->group(function () {
        Route::get('users/{id}', function ($id) {
            return response()->json([
                'received_id' => $id,
                'type' => gettype($id),
                'test' => 'working'
            ]);
        });
        // Debug: shift assignments for today (temporary)
        Route::get('_debug/shifts-today', [\App\Http\Controllers\Api\DebugController::class, 'shiftsToday']);
    });
}); // ✅ Also add a public route (if you want unauthenticated access)
