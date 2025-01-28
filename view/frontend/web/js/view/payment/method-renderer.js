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
                type: 'payflex_gateway',
                component: 'Payflex_Gateway/js/view/payment/method-renderer/payflex-payment'
            }
        );

        return Component.extend({});
    }
);
