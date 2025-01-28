<?php

namespace Payflex\Gateway\Api;

/**
 * Payment method management interface for guest carts.
 * @api
 */
interface GuestPayflexManagementInterface
{
    /**
     * Add a specified payment method to a specified shopping cart.
     *
     * @param string $cartId The cart ID.
     * @param string $email
     * @param \Magento\Quote\Api\Data\PaymentInterface $method The payment method.
     * @param \Magento\Quote\Api\Data\AddressInterface $billingAddress
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException The specified cart does not exist.
     * @throws \Magento\Framework\Exception\State\InvalidTransitionException The billing or shipping address
     * is not set, or the specified payment method is not available.
     */
    public function set($cartId, $email, \Magento\Quote\Api\Data\PaymentInterface $method, \Magento\Quote\Api\Data\AddressInterface $billingAddress = null);
}
