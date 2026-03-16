/**
 * Paypercut BNPL Payment
 */
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        
        var config = window.checkoutConfig.payment.paypercut_bnpl;
        
        if (config && config.isActive && config.isAvailable) {
            rendererList.push({
                type: 'paypercut_bnpl',
                component: 'Paypercut_Payment/js/view/payment/method-renderer/paypercut-bnpl'
            });
        }
        
        return Component.extend({});
    }
);

