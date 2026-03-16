/**
 * Paypercut Payment Method Renderer
 */
define(
    [
        'Magento_Checkout/js/view/payment/default',
        'mage/url'
    ],
    function (Component, url) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Paypercut_Payment/payment/form',
                redirectAfterPlaceOrder: false
            },

            /**
             * Get payment method code
             * @returns {String}
             */
            getCode: function () {
                return 'paypercut_card';
            },

            /**
             * Get payment method data
             * @returns {Object}
             */
            getData: function () {
                return {
                    'method': this.item.method,
                    'additional_data': {}
                };
            },

            /**
             * Get payment description
             * @returns {String}
             */
            getDescription: function () {
                var config = window.checkoutConfig.payment.paypercut_card;
                return config ? config.description : '';
            },

            /**
             * Check if description is available
             * @returns {Boolean}
             */
            hasDescription: function () {
                return !!this.getDescription();
            },

            /**
             * After place order callback - redirect to Paypercut
             */
            afterPlaceOrder: function () {
                window.location.replace(url.build('paypercut/payment/redirect'));
            }
        });
    }
);
