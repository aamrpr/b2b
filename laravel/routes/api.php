<?php

use App\Http\Controllers\Api\V1\Accounting\ChartOfAccountsController;
use App\Http\Controllers\Api\V1\Accounting\JournalController;
use App\Http\Controllers\Api\V1\Accounting\ReportController;
use App\Http\Controllers\Api\V1\Admin\BannerAdminController;
use App\Http\Controllers\Api\V1\Admin\CatalogAdminController;
use App\Http\Controllers\Api\V1\Admin\CategoryAdminController;
use App\Http\Controllers\Api\V1\Admin\TradeAdminController;
use App\Http\Controllers\Api\V1\Admin\UserAdminController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\CommissionRuleController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\EmailTemplateController;
use App\Http\Controllers\Api\V1\Admin\PlatformFinanceController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionController;
use App\Http\Controllers\Api\V1\Admin\UnitAdminController;
use App\Http\Controllers\Api\V1\Admin\SupplierApprovalController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BannerController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Controllers\Api\V1\Inventory\StockCountController;
use App\Http\Controllers\Api\V1\Inventory\StockMovementController;
use App\Http\Controllers\Api\V1\Inventory\WarehouseController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\Payments\CreditNoteController;
use App\Http\Controllers\Api\V1\Payments\PaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Purchasing\VendorController;
use App\Http\Controllers\Api\V1\QuotationController;
use App\Http\Controllers\Api\V1\Returns\DisputeController;
use App\Http\Controllers\Api\V1\Returns\ReturnController;
use App\Http\Controllers\Api\V1\RfqController;
use App\Http\Controllers\Api\V1\Shipping\CarrierController;
use App\Http\Controllers\Api\V1\Shipping\ShipmentController;
use App\Http\Controllers\Api\V1\Shipping\TrackingController;
use App\Http\Controllers\Api\V1\SupplierDirectoryController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Models\Accounting\Currency;
use App\Services\Localization\LocaleResolver;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    /* ---------- عام: لا يتطلب تسجيل دخول ---------- */

    Route::get('meta', fn () => response()->json([
        'locales' => collect(LocaleResolver::supported())->map(fn ($l) => [
            'code' => $l,
            'name' => ['ar' => 'العربية', 'en' => 'English'][$l] ?? $l,
            'dir' => LocaleResolver::isRtl($l) ? 'rtl' : 'ltr',
        ])->values(),
        'currencies' => Currency::active()->orderBy('sort_order')->get()
            ->map(fn ($c) => [
                'code' => $c->code,
                'name' => $c->t('name'),
                'symbol' => $c->symbol,
                'decimals' => $c->decimal_places,
            ]),
        'current_locale' => app()->getLocale(),
    ]));

    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('banners', [BannerController::class, 'index']);
    Route::get('units', [UnitController::class, 'index']);
    Route::get('categories/{slug}', [CategoryController::class, 'show']);

    // دليل المستوردين — المسار (أ)
    Route::get('suppliers', [SupplierDirectoryController::class, 'index']);
    Route::get('suppliers/{slug}', [SupplierDirectoryController::class, 'show']);
    Route::get('suppliers/{slug}/products', [SupplierDirectoryController::class, 'products']);

    // رسالة من الزائر إلى إدارة المنصة: محدودة بالمعدّل كي لا تصير باباً للإزعاج
    Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:5,1');

    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/bestsellers', [ProductController::class, 'bestsellers']);
    Route::get('products/{slug}', [ProductController::class, 'show']);

    // تتبّع عام برمز الشحنة — يفتحه المشتري أو السائق بلا حساب
    Route::get('track/{token}', [TrackingController::class, 'show']);

    Route::post('auth/register/supplier', [AuthController::class, 'registerSupplier']);
    Route::post('auth/register/buyer', [AuthController::class, 'registerBuyer']);
    Route::post('auth/login', [AuthController::class, 'login']);

    /* ---------- يتطلب مصادقة ---------- */

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        /* --- الحساب: كل مستخدم يدير حسابه وشركته --- */
        Route::put('me', [AccountController::class, 'updateProfile']);
        Route::put('me/password', [AccountController::class, 'updatePassword']);
        Route::post('me/avatar', [AccountController::class, 'uploadAvatar']);
        Route::delete('me/avatar', [AccountController::class, 'deleteAvatar']);
        Route::put('my/company', [AccountController::class, 'updateCompany']);
        Route::post('my/company/logo', [AccountController::class, 'uploadLogo']);
        Route::delete('my/company/logo', [AccountController::class, 'deleteLogo']);

        /* --- صور المنتجات: يرفعها مالك المنتج --- */
        Route::post('my/products/{product}/images', [MediaController::class, 'uploadProductImages']);
        Route::put('my/products/{product}/images/order', [MediaController::class, 'reorderProductImages']);
        Route::delete('my/products/{product}/images/{media}', [MediaController::class, 'deleteProductImage']);

        /* --- المستورد: منتجاته --- */
        Route::get('my/products', [ProductController::class, 'mine']);
        Route::post('my/products', [ProductController::class, 'store']);
        Route::put('my/products/{product}', [ProductController::class, 'update']);
        Route::delete('my/products/{product}', [ProductController::class, 'destroy']);

        /* --- طلبات عروض الأسعار — المسار (ب) --- */
        Route::post('rfqs', [RfqController::class, 'store']);          // تاجر الجملة ينشر
        Route::get('my/rfqs', [RfqController::class, 'mine']);          // طلباتي
        Route::get('rfq-inbox', [RfqController::class, 'inbox']);       // الطلبات الواردة للمستورد
        Route::get('rfqs/{rfq}', [RfqController::class, 'show']);
        Route::get('rfqs/{rfq}/stats', [RfqController::class, 'stats']);

        /* --- عروض الأسعار --- */
        Route::post('rfqs/{rfq}/quotations', [QuotationController::class, 'store']);   // المستورد يرسل عرضاً
        Route::get('rfqs/{rfq}/quotations', [QuotationController::class, 'forRfq']);   // جدول المقارنة
        Route::get('my/quotations', [QuotationController::class, 'mine']);
        Route::get('quotations/{quotation}', [QuotationController::class, 'show']);
        Route::post('quotations/{quotation}/sign', [QuotationController::class, 'sign']);  // المستورد يوقّع عرضه
        Route::post('quotations/{quotation}/accept', [QuotationController::class, 'accept']);

        /* --- الطلبات --- */
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);

        /* --- المحادثات --- */
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::post('conversations', [ConversationController::class, 'store']);
        Route::get('conversations/unread-count', [ConversationController::class, 'unreadCount']);
        Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages']);
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'send']);
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'markRead']);
        Route::post('conversations/{conversation}/typing', [ConversationController::class, 'typing'])->middleware('throttle:40,1');

        /* --- الإشعارات --- */
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
        Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

        /* --- المشتريات: مورّدون وأوامر شراء واستلام وفوترة --- */
        Route::get('vendors', [VendorController::class, 'index']);
        Route::post('vendors', [VendorController::class, 'store']);
        Route::put('vendors/{vendor}', [VendorController::class, 'update']);
        Route::delete('vendors/{vendor}', [VendorController::class, 'destroy']);

        Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
        Route::post('purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send']);
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive']);
        Route::post('purchase-orders/{purchaseOrder}/bill', [PurchaseOrderController::class, 'bill']);

        /* --- المدفوعات والتحصيل --- */
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/outstanding', [PaymentController::class, 'outstanding']);
        Route::post('payments/receive', [PaymentController::class, 'receive']);
        Route::post('payments/pay', [PaymentController::class, 'pay']);
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
        Route::post('payments/{payment}/allocate', [PaymentController::class, 'allocate']);
        Route::get('customers/{tenant}/statement', [PaymentController::class, 'statement']);

        Route::get('credit-notes', [CreditNoteController::class, 'index']);
        Route::post('credit-notes/{creditNote}/apply', [CreditNoteController::class, 'apply']);

        /* --- المرتجعات --- */
        Route::get('returns', [ReturnController::class, 'index']);
        Route::get('orders/{order}/returnable', [ReturnController::class, 'returnable']);
        Route::post('orders/{order}/returns', [ReturnController::class, 'store']);
        Route::get('returns/{returnRequest}', [ReturnController::class, 'show']);
        Route::post('returns/{returnRequest}/approve', [ReturnController::class, 'approve']);
        Route::post('returns/{returnRequest}/reject', [ReturnController::class, 'reject']);
        Route::post('returns/{returnRequest}/in-transit', [ReturnController::class, 'markInTransit']);
        Route::post('returns/{returnRequest}/receive', [ReturnController::class, 'receive']);
        Route::post('returns/{returnRequest}/complete', [ReturnController::class, 'complete']);
        Route::post('returns/{returnRequest}/photos', [ReturnController::class, 'uploadPhoto']);

        /* --- النزاعات: الطرفان يشاركان، والمنصة تتوسّط --- */
        Route::get('disputes', [DisputeController::class, 'index']);
        Route::post('orders/{order}/disputes', [DisputeController::class, 'store']);
        Route::get('disputes/{dispute}', [DisputeController::class, 'show']);
        Route::post('disputes/{dispute}/messages', [DisputeController::class, 'addMessage']);
        Route::post('disputes/{dispute}/close', [DisputeController::class, 'close']);

        Route::middleware('platform')->group(function () {
            Route::post('disputes/{dispute}/assign', [DisputeController::class, 'assign']);
            Route::post('disputes/{dispute}/request-response', [DisputeController::class, 'requestResponse']);
            Route::post('disputes/{dispute}/resolve', [DisputeController::class, 'resolve']);
        });

        /* --- الشحن والتتبّع --- */
        Route::get('carriers', [CarrierController::class, 'index']);
        Route::post('carriers', [CarrierController::class, 'store']);
        Route::put('carriers/{carrier}', [CarrierController::class, 'update']);
        Route::delete('carriers/{carrier}', [CarrierController::class, 'destroy']);
        Route::post('carriers/{carrier}/rates', [CarrierController::class, 'storeRate']);
        Route::post('carriers/quote', [CarrierController::class, 'quote']);

        Route::get('shipments', [ShipmentController::class, 'index']);
        Route::get('orders/{order}/shipments/pending', [ShipmentController::class, 'pending']);
        Route::post('orders/{order}/shipments', [ShipmentController::class, 'store']);
        Route::get('shipments/{shipment}', [ShipmentController::class, 'show']);
        Route::patch('shipments/{shipment}/status', [ShipmentController::class, 'transition']);
        Route::post('shipments/{shipment}/events', [ShipmentController::class, 'addEvent']);
        Route::post('shipments/{shipment}/documents', [ShipmentController::class, 'uploadDocument']);

        /* --- المخزون والمستودعات --- */
        Route::prefix('inventory')->group(function () {
            Route::get('warehouses', [WarehouseController::class, 'index']);
            Route::post('warehouses', [WarehouseController::class, 'store']);
            Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update']);
            Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy']);

            Route::get('items', [InventoryController::class, 'index']);
            Route::get('valuation', [InventoryController::class, 'valuation']);
            Route::get('low-stock', [InventoryController::class, 'lowStock']);
            Route::get('items/{inventoryItem}', [InventoryController::class, 'show']);
            Route::put('items/{inventoryItem}/settings', [InventoryController::class, 'updateSettings']);

            Route::get('movements', [StockMovementController::class, 'index']);
            Route::post('receive', [StockMovementController::class, 'receive']);
            Route::post('items/{inventoryItem}/adjust', [StockMovementController::class, 'adjust']);
            Route::post('items/{inventoryItem}/transfer', [StockMovementController::class, 'transfer']);

            Route::get('stock-counts', [StockCountController::class, 'index']);
            Route::post('stock-counts', [StockCountController::class, 'store']);
            Route::get('stock-counts/{stockCount}', [StockCountController::class, 'show']);
            Route::put('stock-counts/{stockCount}/record', [StockCountController::class, 'record']);
            Route::post('stock-counts/{stockCount}/approve', [StockCountController::class, 'approve']);
        });

        /* --- لوحة إدارة المنصة — مقصورة على مستخدمي المنصة --- */
        Route::prefix('admin')->middleware('platform')->group(function () {
            Route::get('dashboard', [DashboardController::class, 'overview']);
            Route::get('dashboard/summary', [DashboardController::class, 'summary']);
            Route::get('dashboard/series', [DashboardController::class, 'series']);
            Route::get('dashboard/top-suppliers', [DashboardController::class, 'topSuppliers']);

            Route::get('suppliers', [SupplierApprovalController::class, 'index']);
            Route::get('suppliers/{tenant}', [SupplierApprovalController::class, 'show']);
            Route::post('suppliers/{tenant}/verify', [SupplierApprovalController::class, 'verify']);
            Route::post('suppliers/{tenant}/reject', [SupplierApprovalController::class, 'reject']);
            Route::post('suppliers/{tenant}/suspend', [SupplierApprovalController::class, 'suspend']);

            /* --- تحكّم المنصة بالكتالوج والمستخدمين والصفقات --- */
            Route::get('products', [CatalogAdminController::class, 'index']);
            Route::patch('products/{product}', [CatalogAdminController::class, 'updateStatus']);
            Route::delete('products/{product}', [CatalogAdminController::class, 'destroy']);

            Route::get('users', [UserAdminController::class, 'index']);
            Route::get('tenants', [UserAdminController::class, 'tenants']);
            Route::patch('users/{user}', [UserAdminController::class, 'update']);
            Route::post('users/{user}/password', [UserAdminController::class, 'resetPassword']);

            Route::get('quotations', [TradeAdminController::class, 'quotations']);
            Route::get('orders', [TradeAdminController::class, 'orders']);
            Route::post('orders/{order}/status', [TradeAdminController::class, 'updateOrderStatus']);

            Route::get('categories', [CategoryAdminController::class, 'index']);
            Route::post('categories', [CategoryAdminController::class, 'store']);
            Route::put('categories/{category}', [CategoryAdminController::class, 'update']);
            Route::delete('categories/{category}', [CategoryAdminController::class, 'destroy']);

            Route::get('banners', [BannerAdminController::class, 'index']);
            Route::post('banners', [BannerAdminController::class, 'store']);
            Route::put('banners/{banner}', [BannerAdminController::class, 'update']);
            Route::post('banners/{banner}/image', [BannerAdminController::class, 'image']);
            Route::delete('banners/{banner}', [BannerAdminController::class, 'destroy']);

            Route::get('email-templates', [EmailTemplateController::class, 'index']);
            Route::put('email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])
                ->where('emailTemplate', '[a-z]+');

            Route::get('units', [UnitAdminController::class, 'index']);
            Route::post('units', [UnitAdminController::class, 'store']);
            Route::put('units/{unit}', [UnitAdminController::class, 'update']);
            Route::delete('units/{unit}', [UnitAdminController::class, 'destroy']);

            Route::get('commission-rules', [CommissionRuleController::class, 'index']);
            Route::post('commission-rules', [CommissionRuleController::class, 'store']);
            Route::put('commission-rules/{commissionRule}', [CommissionRuleController::class, 'update']);
            Route::delete('commission-rules/{commissionRule}', [CommissionRuleController::class, 'destroy']);

            Route::get('plans', [SubscriptionController::class, 'plans']);
            Route::put('plans/{plan}', [SubscriptionController::class, 'updatePlan']);
            Route::get('subscriptions', [SubscriptionController::class, 'index']);

            Route::get('finance/commissions', [PlatformFinanceController::class, 'commissions']);
            Route::get('finance/commission-aging', [PlatformFinanceController::class, 'commissionAging']);
            Route::post('finance/commissions/{commission}/settle', [PlatformFinanceController::class, 'settle']);
            Route::post('finance/suppliers/{tenant}/settle', [PlatformFinanceController::class, 'settleSupplier']);

            Route::get('contact-messages', [ContactController::class, 'index']);
            Route::patch('contact-messages/{contactMessage}', [ContactController::class, 'update']);

            Route::get('audit-logs', [AuditLogController::class, 'index']);

            // صور الأصناف: الأصناف عامة للمنصة فتديرها إدارتها
            Route::post('categories/{category}/image', [MediaController::class, 'uploadCategoryImage']);
            Route::delete('categories/{category}/image', [MediaController::class, 'deleteCategoryImage']);
        });

        /* --- المحاسبة: تعمل لدفتر المستورد ولدفتر المنصة بنفس المسارات --- */
        Route::prefix('accounting')->group(function () {
            Route::get('accounts', [ChartOfAccountsController::class, 'index']);
            Route::post('accounts', [ChartOfAccountsController::class, 'store']);

            Route::get('journal', [JournalController::class, 'index']);
            Route::post('journal', [JournalController::class, 'store']);
            Route::get('journal/{journalEntry}', [JournalController::class, 'show']);
            Route::post('journal/{journalEntry}/reverse', [JournalController::class, 'reverse']);

            Route::get('reports/trial-balance', [ReportController::class, 'trialBalance']);
            Route::get('reports/income-statement', [ReportController::class, 'incomeStatement']);
            Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet']);
            Route::get('reports/receivables-aging', [ReportController::class, 'receivablesAging']);
        });
    });
});
