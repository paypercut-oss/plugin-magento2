/**
 * Paypercut Payment
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
        rendererList.push(
            {
                type: 'paypercut_card',
                component: 'Paypercut_Payment/js/view/payment/method-renderer/paypercut'
            }
        );
        return Component.extend({});
    }
);
