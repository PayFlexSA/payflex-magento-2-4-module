define([ 'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Checkout/js/model/error-processor',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/model/full-screen-loader',
        'Magento_Customer/js/customer-data',
        'Magento_Ui/js/modal/modal'], 
        function($, quote, urlBuilder, storage, errorProcessor, customer, fullScreenLoader, customerData, modal){
    
    'use strict';

    return function(messageContainer, module, paymentData, successAction, successActionParameters){
        var serviceUrl, payload;

        /**
         * Checkout for guest and registered customer.
         */
        if (!customer.isLoggedIn()) {
            serviceUrl = urlBuilder.createUrl('/guest-carts/:cartId/:module/selected-payment-method', {
                        cartId : quote.getQuoteId(),
                        module : module
                    });
            payload = {
                cartId : quote.getQuoteId(),
                email: quote.guestEmail,
                method : paymentData,
                billingAddress: quote.billingAddress() 
            };
        } else {
            serviceUrl = urlBuilder.createUrl('/carts/mine/:module/selected-payment-method', {module : module});
            payload = {
                cartId : quote.getQuoteId(),
                method : paymentData,
                billingAddress: quote.billingAddress()
            };
        }
        fullScreenLoader.startLoader();
        
        console.log("payload:" + JSON.stringify(payload));

        return storage.put(serviceUrl, JSON.stringify(payload)).done(
                function(response){
                    console.log("response from server:" + response);
                    successActionParameters.sessionId = response;
                    successAction(successActionParameters);
                }).fail(
                function(response){
                    console.log("response from server:" + response);
                    fullScreenLoader.stopLoader();
                    try {
                        errorProcessor.process(response, messageContainer);
                    }
                    catch (e) {
                        var errorResponse = { status: 500, responseText: JSON.stringify({ message: "Internal server error" }) };
                        errorProcessor.process(errorResponse, messageContainer);

                        var options = {
                            type: 'popup',
                            responsive: true,
                            innerScroll: true,
                            title: 'Internal server error.',
                            buttons: [{
                                text: $.mage.__('Continue'),
                                class: '',
                                click: function () {
                                    this.closeModal();
                                }
                            }]
                        };
                        $('<div></div>').html('Please contact support.').modal(options).modal('openModal');
                    }
        }).always(
                function () {
                    customerData.invalidate(['cart']);
                }
        );
    };
});
