/**
 * Paypercut BNPL Payment Method Renderer
 */
define(
    [
        'ko',
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'mage/url',
        'mage/translate'
    ],
    function (ko, $, Component, quote, url, $t) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Paypercut_Payment/payment/bnpl-form',
                selectedInstallments: ko.observable(null),
                redirectAfterPlaceOrder: false
            },

            /**
             * Initialize component
             */
            initialize: function () {
                this._super();
                
                // Set default installment option
                var options = this.getInstallmentOptions();
                if (options.length > 0) {
                    this.selectedInstallments(options[0].months);
                }
                
                return this;
            },

            /**
             * Get payment method code
             * @returns {String}
             */
            getCode: function () {
                return 'paypercut_bnpl';
            },

            /**
             * Get payment method data
             * @returns {Object}
             */
            getData: function () {
                return {
                    'method': this.item.method,
                    'additional_data': {
                        'installments': this.selectedInstallments()
                    }
                };
            },

            /**
             * Check if BNPL is available
             * @returns {Boolean}
             */
            isAvailable: function () {
                var config = window.checkoutConfig.payment.paypercut_bnpl;
                return config && config.isAvailable;
            },

            /**
             * Check if installment preview should be shown
             * @returns {Boolean}
             */
            showInstallmentPreview: function () {
                var config = window.checkoutConfig.payment.paypercut_bnpl;
                return config ? !!config.showInstallmentPreview : true;
            },

            /**
             * Get installment options
             * @returns {Array}
             */
            getInstallmentOptions: function () {
                var config = window.checkoutConfig.payment.paypercut_bnpl;
                return config ? config.installmentOptions : [];
            },

            /**
             * Get minimum order total for BNPL
             * @returns {Number}
             */
            getMinOrderTotal: function () {
                var config = window.checkoutConfig.payment.paypercut_bnpl;
                return config ? config.minOrderTotal : 0;
            },

            /**
             * Get maximum order total for BNPL
             * @returns {Number}
             */
            getMaxOrderTotal: function () {
                var config = window.checkoutConfig.payment.paypercut_bnpl;
                return config ? config.maxOrderTotal : 0;
            },

            /**
             * Get BNPL description text
             * @returns {String}
             */
            getBnplDescription: function () {
                return $t('Pay in installments without interest. Choose the number of installments and pay monthly.');
            },

            /**
             * Select installment option
             * @param {Object} option
             */
            selectInstallment: function (option) {
                this.selectedInstallments(option.months);
                return true;
            },

            /**
             * Check if installment is selected
             * @param {Object} option
             * @returns {Boolean}
             */
            isInstallmentSelected: function (option) {
                return this.selectedInstallments() === option.months;
            },

            /**
             * Get calculated monthly amount for selected plan
             * @returns {String}
             */
            getSelectedMonthlyAmount: function () {
                var options = this.getInstallmentOptions();
                var selected = this.selectedInstallments();
                
                for (var i = 0; i < options.length; i++) {
                    if (options[i].months === selected) {
                        return options[i].monthlyAmount;
                    }
                }
                
                return '';
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

