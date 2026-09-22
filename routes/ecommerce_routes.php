<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Ecommerce\EcommerceController;

Route::prefix('ecommerce')->group(function () {
    Route::post('/stores/{storeId}/follow', [EcommerceController::class, 'followStore']);
    Route::delete('/stores/{storeId}/follow', [EcommerceController::class, 'unfollowStore']);

    Route::get('/carts', [EcommerceController::class, 'carts']);
    Route::get('/cart', [EcommerceController::class, 'getCart']);
    Route::post('/cart/items', [EcommerceController::class, 'addCartItem']);
    Route::put('/cart/items/{itemId}', [EcommerceController::class, 'updateCartItem']);
    Route::delete('/cart/items/{itemId}', [EcommerceController::class, 'removeCartItem']);
    Route::post('/cart/clear', [EcommerceController::class, 'clearCart']);
    Route::get('/address-templates', [EcommerceController::class, 'addressTemplates']);
    Route::post('/address-templates', [EcommerceController::class, 'storeAddressTemplate']);
    Route::put('/address-templates/{id}', [EcommerceController::class, 'updateAddressTemplate']);
    Route::post('/vouchers/validate', [EcommerceController::class, 'validateVoucher']);
    Route::post('/shipping/estimate', [EcommerceController::class, 'estimateShippingFee']);

    Route::get('/favorites', [EcommerceController::class, 'favorites']);
    Route::post('/favorites/toggle', [EcommerceController::class, 'toggleFavorite']);

    Route::post('/checkout', [EcommerceController::class, 'checkout']);
    Route::post('/dss/recommendations', [EcommerceController::class, 'dssRecommendations']);
    Route::get('/orders', [EcommerceController::class, 'orders']);
    Route::get('/orders/{id}', [EcommerceController::class, 'orderShow']);
    Route::post('/orders/{id}/cancel-requests', [EcommerceController::class, 'requestOrderCancellation']);
    Route::post('/order-items/{itemId}/return-requests', [EcommerceController::class, 'requestOrderReturn']);
    Route::put('/returns/{return}/refund-payment-method', [EcommerceController::class, 'updateRefundPaymentMethod']);
    Route::post('/order-items/{itemId}/reviews', [EcommerceController::class, 'submitItemReview']);
    Route::post('/violations/report', [EcommerceController::class, 'reportViolation']);

    Route::get('/chat/threads', [EcommerceController::class, 'chatThreads']);
    Route::get('/chat/stores/{storeId}/messages', [EcommerceController::class, 'chatMessages']);
    Route::post('/chat/stores/{storeId}/messages', [EcommerceController::class, 'sendChatMessage']);
    Route::put('/chat/stores/{storeId}/messages/{messageId}', [EcommerceController::class, 'updateChatMessage']);
    Route::delete('/chat/stores/{storeId}/messages/{messageId}', [EcommerceController::class, 'unsendChatMessage']);
});
