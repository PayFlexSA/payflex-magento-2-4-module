<?php
/**
 * Admin / order payment information block for the Payflex gateway.
 *
 * Centralises all presentation logic so the template stays dumb: the block
 * exposes a curated, ordered set of "primary" rows (transaction id, status,
 * reference, amount, ...) plus an "additional" bucket for any other key the
 * Payflex API may have merged into the payment's additional information.
 *
 * The same curated data is fed to {@see _prepareSpecificInformation()} so the
 * standard Magento rendering paths (order view, invoice PDF, emails) stay
 * consistent with the custom admin template.
 */

namespace Payflex\Gateway\Block;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\DataObject;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Block\Info as PaymentInfo;
use Payflex\Gateway\Logger\PayflexLogger;

class Info extends PaymentInfo
{
    /**
     * @var string
     */
    protected $_template = 'Payflex_Gateway::info/default.phtml';

    /**
     * Additional-information keys that must never be rendered: either sensitive
     * (auth token, customer email) or internal plumbing (cart id, redirect url).
     * The transaction id, status and processed time are surfaced through their
     * own dedicated getters, so they are excluded from the generic tables too.
     */
    private const HIDDEN_KEYS = [
        'items', 'billing', 'shipping', 'merchant',
        'cartId', 'guestEmail', 'token', 'expire', 'url',
        'method_title', 'orderId', 'payflex_id', 'orderStatus',
        'payflex_status_checked_at', 'payflex_processed_at',
        'payflex_module_version',
    ];

    /**
     * Curated, ordered list of the keys we surface first, each with a human
     * label. The order here is the order shown in the template.
     */
    private const PRIMARY_FIELDS = [
        'merchantReference' => 'Merchant Reference',
        'amount'            => 'Amount',
        'currency'          => 'Currency',
    ];

    /**
     * @var PayflexLogger
     */
    private $payflexLogger;

    /**
     * @var FormKey
     */
    private $formKey;

    /**
     * @var TimezoneInterface
     */
    private $localeDate;

    /**
     * @var array|null Cached copy of the payment additional information.
     */
    private $infoData;

    public function __construct(
        Context $context,
        PayflexLogger $payflexLogger,
        FormKey $formKey,
        TimezoneInterface $localeDate,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->payflexLogger = $payflexLogger;
        $this->formKey = $formKey;
        $this->localeDate = $localeDate;
        $this->payflexLogger->info(__METHOD__);
    }

    /**
     * Payment additional information as a plain array (cached per request).
     *
     * @return array
     */
    public function getInfoData(): array
    {
        if ($this->infoData === null) {
            $info = $this->getInfo();
            $this->infoData = $info ? (array) $info->getAdditionalInformation() : [];
        }

        return $this->infoData;
    }

    /**
     * Single additional-information value, or null when absent / not scalar.
     *
     * @param string $key
     * @return string|null
     */
    public function getValue(string $key): ?string
    {
        $data = $this->getInfoData();
        if (!array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];
        if (is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * The Payflex transaction / order id.
     *
     * Falls back through the known additional-information keys and finally to
     * the Magento transaction id, which the gateway sets to the same value.
     *
     * @return string|null
     */
    public function getTransactionId(): ?string
    {
        $id = $this->getValue('orderId') ?? $this->getValue('payflex_id');
        if ($id !== null) {
            return $id;
        }

        $info = $this->getInfo();
        if ($info && $info->getLastTransId()) {
            return (string) $info->getLastTransId();
        }

        return null;
    }

    /**
     * Raw Payflex status string (e.g. Approved, Declined, Created, Not Set).
     *
     * @return string|null
     */
    public function getPayflexStatus(): ?string
    {
        return $this->getValue('orderStatus');
    }

    /**
     * The Payflex plugin version recorded when the order was placed, or null
     * for orders placed before version stamping was introduced.
     *
     * @return string|null
     */
    public function getModuleVersion(): ?string
    {
        return $this->getValue('payflex_module_version');
    }

    /**
     * CSS modifier class for a Payflex status, used for colour-coding.
     *
     * @param string|null $status
     * @return string
     */
    public function getStatusClass(?string $status): string
    {
        switch (strtolower((string) $status)) {
            case 'approved':
                return 'payflex-status--approved';
            case 'declined':
            case 'abandoned':
            case 'cancelled':
            case 'canceled':
            case 'failed':
                return 'payflex-status--declined';
            default:
                return 'payflex-status--pending';
        }
    }

    /**
     * Amount formatted with two decimals and the transaction currency code.
     *
     * Prefers the amount Payflex recorded; falls back to the Magento order
     * grand total so the amount is shown even for orders placed before the
     * gateway stored it in additional information.
     *
     * @return string|null
     */
    public function getFormattedAmount(): ?string
    {
        $amount = $this->getValue('amount');
        if ($amount !== null && is_numeric($amount)) {
            return $this->formatMoney((float) $amount);
        }

        $order = $this->getOrder();
        if ($order !== null && $order->getGrandTotal() !== null) {
            return $this->formatMoney((float) $order->getGrandTotal(), $order->getOrderCurrencyCode());
        }

        return null;
    }

    /**
     * Format a numeric amount with two decimals and a currency code, defaulting
     * to the Payflex transaction currency when none is supplied.
     *
     * @param float $amount
     * @param string|null $currency
     * @return string
     */
    public function formatMoney($amount, ?string $currency = null): string
    {
        $formatted = number_format((float) $amount, 2);
        $currency = $currency ?? $this->getValue('currency');

        return $currency ? $currency . ' ' . $formatted : $formatted;
    }

    /**
     * Refunds recorded against this payment, newest entries last.
     *
     * The Payflex refund flow stores each refund response as a datetime-keyed
     * JSON blob in additional information; this parses them into a clean list.
     *
     * @return array List of ['id', 'amount', 'amount_formatted', 'date', 'reference'].
     */
    public function getRefunds(): array
    {
        $refunds = [];

        foreach ($this->getInfoData() as $value) {
            $refund = $this->decodeRefund($value);
            if ($refund !== null) {
                $refunds[] = $refund;
            }
        }

        return $refunds;
    }

    /**
     * Whether at least one Payflex refund has been recorded.
     *
     * @return bool
     */
    public function isRefunded(): bool
    {
        return $this->getRefunds() !== [];
    }

    /**
     * Total amount refunded across all recorded Payflex refunds.
     *
     * @return float
     */
    public function getRefundedTotal(): float
    {
        $total = 0.0;
        foreach ($this->getRefunds() as $refund) {
            if ($refund['amount'] !== null) {
                $total += (float) $refund['amount'];
            }
        }

        return $total;
    }

    /**
     * Formatted total refunded amount, with currency.
     *
     * @return string
     */
    public function getFormattedRefundedTotal(): string
    {
        return $this->formatMoney($this->getRefundedTotal());
    }

    /**
     * Pill label describing the refund state, or null when not refunded.
     * Distinguishes a full refund from a partial one against the captured amount.
     *
     * @return string|null
     */
    public function getRefundPillLabel(): ?string
    {
        if (!$this->isRefunded()) {
            return null;
        }

        $captured = $this->getValue('amount');
        $captured = ($captured !== null && is_numeric($captured)) ? (float) $captured : null;

        if ($captured !== null && $this->getRefundedTotal() + 0.01 < $captured) {
            return (string) __('Partially Refunded');
        }

        return (string) __('Refunded');
    }

    /**
     * Parse a single additional-information value into a refund record, or null
     * when it is not a Payflex refund response.
     *
     * @param mixed $value
     * @return array|null
     */
    private function decodeRefund($value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        } elseif (is_object($value)) {
            $value = (array) $value;
        }

        if (!is_array($value) || !isset($value['refundedDateTime'])) {
            return null;
        }

        $date = $value['refundedDateTime'];
        try {
            $date = $this->localeDate->date($value['refundedDateTime'])->format('M j, Y g:i A');
        } catch (\Exception $e) {
            // Fall back to the raw timestamp.
        }

        $amount = isset($value['amount']) && is_numeric($value['amount']) ? (float) $value['amount'] : null;

        return [
            'id'               => isset($value['id']) ? (string) $value['id'] : null,
            'amount'           => $amount,
            'amount_formatted' => $amount !== null ? $this->formatMoney($amount) : null,
            'date'             => $date,
            'reference'        => isset($value['merchantReference']) ? (string) $value['merchantReference'] : null,
        ];
    }

    /**
     * Curated primary rows, in display order. Each entry is
     * ['label' => string, 'value' => string, 'key' => string] with the
     * status/amount values already formatted.
     *
     * @return array
     */
    public function getPrimaryRows(): array
    {
        $rows = [];

        foreach (self::PRIMARY_FIELDS as $key => $label) {
            $value = $key === 'amount' ? $this->getFormattedAmount() : $this->getValue($key);

            if ($value === null) {
                continue;
            }

            $rows[] = ['label' => $label, 'value' => $value, 'key' => $key];
        }

        return $rows;
    }

    /**
     * The Magento order this payment belongs to, or null when the block is
     * rendered outside an order context (e.g. a quote payment).
     *
     * @return \Magento\Sales\Api\Data\OrderInterface|null
     */
    private function getOrder()
    {
        $info = $this->getInfo();
        if ($info && method_exists($info, 'getOrder') && $info->getOrder() && $info->getOrder()->getId()) {
            return $info->getOrder();
        }

        return null;
    }

    /**
     * The Magento order entity id this payment belongs to, or null when the
     * block is rendered outside an order context (e.g. a quote payment).
     *
     * @return int|null
     */
    public function getOrderEntityId(): ?int
    {
        $order = $this->getOrder();

        return $order !== null ? (int) $order->getId() : null;
    }

    /**
     * Current Magento order status label, for side-by-side comparison with the
     * Payflex status.
     *
     * @return string|null
     */
    public function getOrderStatusLabel(): ?string
    {
        $order = $this->getOrder();

        return $order !== null ? (string) $order->getStatusLabel() : null;
    }

    /**
     * The live Payflex status as last verified against the API, or null when it
     * has never been checked manually. Distinct from {@see getPayflexStatus()},
     * which is the status the order currently has on record.
     *
     * @return string|null
     */
    public function getVerifiedStatus(): ?string
    {
        return $this->getStatusCheckedAt() !== null ? $this->getPayflexStatus() : null;
    }

    /**
     * Admin URL for the read-only "check status" endpoint.
     *
     * @return string
     */
    public function getCheckStatusUrl(): string
    {
        return $this->getUrl('payflex/order/checkStatus', ['order_id' => $this->getOrderEntityId()]);
    }

    /**
     * Admin URL for the mutating "update status" endpoint.
     *
     * @return string
     */
    public function getUpdateStatusUrl(): string
    {
        return $this->getUrl('payflex/order/updateStatus', ['order_id' => $this->getOrderEntityId()]);
    }

    /**
     * Admin form key, required for the POST update request.
     *
     * @return string
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * URL to the Payflex admin logo. Resolved through the module's view assets
     * so it works regardless of whether the module lives in app/code or vendor/.
     *
     * @return string
     */
    public function getLogoUrl(): string
    {
        return $this->getViewFileUrl('Payflex_Gateway::images/admin_logo.svg');
    }

    /**
     * When the Payflex status was last fetched from the API (manual confirm or
     * reconcile), formatted in the admin timezone. Null when never checked.
     *
     * @return string|null
     */
    public function getStatusCheckedAt(): ?string
    {
        $raw = $this->getValue('payflex_status_checked_at');
        if (!$raw) {
            return null;
        }

        try {
            return $this->localeDate->date($raw)->format('M j, Y g:i A');
        } catch (\Exception $e) {
            return $raw;
        }
    }

    /**
     * When the payment was processed/captured, formatted in admin timezone.
     *
     * Prefers the invoice (capture) time; falls back to a stored
     * payflex_processed_at marker. Returns null when not yet processed.
     *
     * @return string|null
     */
    public function getProcessedAt(): ?string
    {
        $raw = null;

        $info = $this->getInfo();
        if ($info && method_exists($info, 'getOrder') && $info->getOrder() && $info->getOrder()->getId()) {
            $invoices = $info->getOrder()->getInvoiceCollection();
            if ($invoices && $invoices->getSize() > 0) {
                $raw = $invoices->getFirstItem()->getCreatedAt();
            }
        }

        if (!$raw) {
            $raw = $this->getValue('payflex_processed_at');
        }

        if (!$raw) {
            return null;
        }

        try {
            return $this->localeDate->date($raw)->format('M j, Y g:i A');
        } catch (\Exception $e) {
            return $raw;
        }
    }

    /**
     * Any remaining displayable additional-information entries that are neither
     * hidden nor already shown as a primary row.
     *
     * @return array List of ['label' => string, 'value' => string].
     */
    public function getAdditionalRows(): array
    {
        $rows = [];

        foreach ($this->getInfoData() as $key => $value) {
            if (in_array($key, self::HIDDEN_KEYS, true)
                || array_key_exists($key, self::PRIMARY_FIELDS)) {
                continue;
            }

            // Refund records and datetime-keyed API logs are surfaced separately.
            if ($this->decodeRefund($value) !== null
                || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $key)) {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $rows[] = [
                'label' => $this->humanizeKey($key),
                'value' => $value,
            ];
        }

        return $rows;
    }

    /**
     * Turn a raw key into a readable label
     * ("merchantReference" => "Merchant Reference").
     *
     * @param string $key
     * @return string
     */
    public function humanizeKey(string $key): string
    {
        $key = preg_replace('/(?<!^)([A-Z])/', ' $1', $key);
        $key = str_replace(['_', '-'], ' ', $key);

        return ucwords(trim($key));
    }

    /**
     * Keep the standard Magento info transport in sync with the custom template
     * so order PDFs / emails show the same curated values.
     *
     * @param DataObject|null $transport
     * @return DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $this->payflexLogger->info(__METHOD__);

        if ($this->_paymentSpecificInformation !== null) {
            return $this->_paymentSpecificInformation;
        }

        $transport = parent::_prepareSpecificInformation($transport);

        $display = [];

        if ($this->getTransactionId() !== null) {
            $display[(string) __('Payflex Transaction ID')] = $this->getTransactionId();
        }
        if ($this->getPayflexStatus() !== null) {
            $display[(string) __('Payflex Status')] = $this->getPayflexStatus();
        }
        foreach ($this->getPrimaryRows() as $row) {
            $display[(string) __($row['label'])] = $row['value'];
        }
        if ($this->getProcessedAt() !== null) {
            $display[(string) __('Processed')] = $this->getProcessedAt();
        }
        if ($this->isRefunded()) {
            $display[(string) __('Refund Status')] = $this->getRefundPillLabel();
            $display[(string) __('Refunded Amount')] = $this->getFormattedRefundedTotal();
        }
        if ($this->getModuleVersion() !== null) {
            $display[(string) __('Plugin Version')] = $this->getModuleVersion();
        }
        foreach ($this->getAdditionalRows() as $row) {
            $display[(string) __($row['label'])] = $row['value'];
        }

        if (empty($display[(string) __('Payment Method')])) {
            $method = $this->getInfo() ? $this->getInfo()->getMethodInstance() : null;
            $display[(string) __('Payment Method')] = $method ? $method->getTitle() : 'Payflex';
        }

        $this->_paymentSpecificInformation = $transport->setData(
            array_merge($transport->getData(), $display)
        );

        return $this->_paymentSpecificInformation;
    }
}
