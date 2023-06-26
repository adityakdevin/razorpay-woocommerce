<?php


const WC_ORDER_ID                    = 'woocommerce_order_id';
const RAZORPAY_PAYMENT_ID            = 'razorpay_payment_id';
const RAZORPAY_ORDER_ID              = 'razorpay_order_id';
const RAZORPAY_SIGNATURE             = 'razorpay_signature';
const RAZORPAY_OFFER                 = 'razorpay_offer';

function prepayCODOrder(array $payload): WP_REST_Response {
    $wcRazorpay = new WC_Razorpay(false);

    $orderId = $payload[WC_ORDER_ID];
    $razorpayPaymentId = $payload[RAZORPAY_PAYMENT_ID];
    $razorpayOrderId = $payload[RAZORPAY_ORDER_ID];
    $signature = $payload[RAZORPAY_SIGNATURE];
    $razorpayOffer = $payload[RAZORPAY_OFFER];
    $attributes = array(
        RAZORPAY_ORDER_ID => $razorpayOrderId,
        RAZORPAY_PAYMENT_ID => $razorpayPaymentId,
        RAZORPAY_SIGNATURE  => $signature,
    );

    try
    {
        $api = $wcRazorpay->getRazorpayApiInstance();
        $api->utility->verifyPaymentSignature($attributes);
    }
    catch (Exception $e)
    {
        return new WP_REST_Response(["code" => 'woocommerce_order_payment_signature_verfication_failed'], 400);
    }
    $order = wc_get_order($orderId);
    if ('on-hold' != $order->get_status())
    {
        return new WP_REST_Response(['code' => 'woocommerce_order_not_in_on_hold_status'], 400);
    }

    if ((isset($payload['coupon']) || $razorpayOffer > 0) && get_option("woocommerce_enable_coupons") === "no")
    {
        return new WP_REST_Response(['code' => 'woocommerce_merchant_coupon_feature_disabled'], 400);
    }

    $order->set_status('pending');
    $order->save();
    $couponInput = [];

    if (isset($payload['coupon']))
    {
        $couponKey = $payload['coupon']['code'];
        $amount = $payload['coupon']['amount'];
        $amountInRs = $amount/100;
        $couponInput[$couponKey] = $amountInRs;
    }
    if ($razorpayOffer > 0)
    {
        $razorpayOfferInRs = $razorpayOffer/100;
        $couponKey = 'Razorpay offers_'. $orderId .'(₹'. $razorpayOfferInRs .')';
        $couponInput[$couponKey] = $razorpayOfferInRs;
    }

    if (sizeof($couponInput) > 0 )
    {
        $error = bulkCouponApply($couponInput, $wcRazorpay, $order);
        if (sizeof($error) > 0 )
        {
            handleCouponFailure($couponInput, $order);
            return new WP_REST_Response($error, 500);
        }
    }
    $order->set_payment_method($wcRazorpay->id);
    $order->set_payment_method_title($wcRazorpay->title);
    $order->payment_complete($razorpayPaymentId);
    $order->set_status("processing");
    $order->save();
    $order->add_order_note("COD Order Converted to Prepaid <br/> Razorpay payment successful <br/>Razorpay Id: $razorpayPaymentId");
    return new WP_REST_Response([], 200);
}

function handleCouponFailure($couponInput, $order) {
    foreach($couponInput as $couponCode => $amount) {
        $order->remove_coupon($couponCode);
    }
    $order->set_status('on-hold');
    $order->save();
}

function bulkCouponApply($input, $wcRazorpay, $order) : array {
    foreach($input as $couponCode => $amount) {
        $isSuccess = createCoupon($couponCode, $amount);
        if ($isSuccess === false) {
            rzpLogError("Prepay cod create coupon error, key : " . $couponCode);
            return['code' => 'woocommerce_create_new_'. $couponCode .'_coupon_error'];
        }
        $wcRazorpay->applyCoupon($order, $couponCode, $amount);
    }
    return [];
}

function createCoupon($coupon_code, $amount) : bool {
    $coupon = array(
        'post_title' => $coupon_code,
        'post_content' => '',
        'post_status' => 'publish',
        'post_author' => 1,
        'post_type' => 'shop_coupon');

    $new_coupon_id = wp_insert_post( $coupon );

    if( $new_coupon_id === 0) {
        return false;
    }

    $input = [
        'discount_type' => 'fixed_cart',
        'coupon_amount' => $amount,
        'usage_limit' => 1,
        'minimum_amount' => $amount,
    ];

    return bulkUpdatePostMeta($new_coupon_id, $input);
}

function bulkUpdatePostMeta($id, $input): bool {
    foreach ($input as $key => $value) {
        $isSuccess = update_post_meta($id, $key, $value);
        if($isSuccess === false) {
			rzpLogError("Prepay COD create coupon : update post meta error, key : " . $key . ", value : " . $value);
            return false;
        }
    }
    return true;
}
