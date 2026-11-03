/*
 * Copyright (c) 2020 Peach Payments. All rights reserved. Developed by Francois Raubenheimer
 */

define(
  [
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
  ],
  function (
    Component,
    url
  ) {
    'use strict';
    var payflexConfig = window.checkoutConfig.payment.payflex;
    return Component.extend({
      defaults: {
          template: 'Payflex_Gateway/payment/payflex-payment',
          redirectAfterPlaceOrder: false
      },
      getData: function() {
          return {
              'method': payflexConfig.method,
              'additional_data': {}
          };
      },
      afterPlaceOrder: function () {
        window.location.replace(url.build('payflex/order/redirect'));
      }
    });
  }
);
