/**
 * Paypercut Vault Payment Method Renderer
 * For saved cards
 */
define([
    'Magento_Vault/js/view/payment/method-renderer/vault'
], function (VaultComponent) {
    'use strict';

    return VaultComponent.extend({
        defaults: {
            template: 'Paypercut_Payment/payment/vault'
        },

        /**
         * Get last 4 digits of card number
         * @returns {String}
         */
        getMaskedCard: function () {
            return this.details.maskedCC;
        },

        /**
         * Get card type
         * @returns {String}
         */
        getCardType: function () {
            return this.details.type;
        },

        /**
         * Get expiration date
         * @returns {String}
         */
        getExpirationDate: function () {
            return this.details.expirationDate;
        },

        /**
         * Get card icon
         * @returns {String}
         */
        getIcons: function (type) {
            return window.checkoutConfig.payment.ccform.icons.hasOwnProperty(type)
                ? window.checkoutConfig.payment.ccform.icons[type]
                : false;
        },

        /**
         * Get public hash
         * @returns {String}
         */
        getToken: function () {
            return this.publicHash;
        }
    });
});

