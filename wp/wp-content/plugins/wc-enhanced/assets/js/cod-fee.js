jQuery(function ($) {
    // Listen for payment method change and trigger checkout update
    $(document.body).on('change', 'input[name="payment_method"]', function () {
        $(document.body).trigger('update_checkout');
    });
});
