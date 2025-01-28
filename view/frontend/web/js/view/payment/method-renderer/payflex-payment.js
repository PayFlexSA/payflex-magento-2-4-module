/*
 * Copyright (c) 2020 Peach Payments. All rights reserved. Developed by Francois Raubenheimer
 */

define(
  [
    'jquery',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder',
    'mage/storage',
    'Magento_Customer/js/customer-data',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/action/place-order',
    'Magento_Checkout/js/action/select-payment-method',
    'Magento_Customer/js/model/customer',
    'Magento_Checkout/js/checkout-data',
    'Magento_Checkout/js/model/payment/additional-validators',
    'mage/url',
    'Payflex_Gateway/js/action/set-payment'
  ],
  function (
    $,
    quote,
    urlBuilder,
    storage,
    customerData,
    Component,
    placeOrderAction,
    selectPaymentMethodAction,
    customer,
    checkoutData,
    additionalValidators,
    url,
    setPaymentMethodAction
  ) {
    'use strict';
    var payflexConfig = window.checkoutConfig.payment.payflex;
    return Component.extend({
      initialize: function () {
        self = this;
        this._super();
      },
      defaults: {
          template: 'Payflex_Gateway/payment/payflex-payment'
      },
      beforePlaceOrderForpayflex:function(redirectUrl){
        var placeOrder;
        placeOrder = placeOrderAction(this.getData(), false, this.messageContainer);
          $.when(placeOrder).fail(function () {
            self.isPlaceOrderActionAllowed(true);
          }).done(function () { window.location.replace(redirectUrl);});
      },
      redirectToPayflex: function (parameters) {
        self.beforePlaceOrderForpayflex(parameters.sessionId);
      },
      getData: function() {
          var additionalData = {};
          
          if (!customer.isLoggedIn()){
              additionalData["cartId"] = quote.getQuoteId();
              additionalData["guestEmail"] = quote.guestEmail;
          }
          var data = {
              'method': payflexConfig.method,
              'additional_data' : additionalData
          };

          return data;
       },
      placeOrder: function (data, event) {
        if (event) {
          event.preventDefault();
        }
        var emailValidationResult = customer.isLoggedIn(),
        loginFormSelector = 'form[data-role=email-with-possible-login]';

        if (!customer.isLoggedIn()) {
          $(loginFormSelector).validation();
          emailValidationResult = Boolean($(loginFormSelector + ' input[name=username]').valid());
        }
        if (emailValidationResult && this.validate() && additionalValidators.validate()) {
          this.isPlaceOrderActionAllowed(false);
          setPaymentMethodAction(this.messageContainer, "payflex", this.getData(), this.redirectToPayflex, {})
        }
        return false;
      },
      selectPaymentMethod: function () {
        selectPaymentMethodAction(this.getData());
        checkoutData.setSelectedPaymentMethod(this.item.method);
        return true;
      },
    });
  }
);
