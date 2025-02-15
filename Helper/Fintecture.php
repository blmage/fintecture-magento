<?php

declare(strict_types=1);

namespace Fintecture\Payment\Helper;

use Fintecture\Payment\Gateway\Config\Config;
use Fintecture\Payment\Logger\Logger as FintectureLogger;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\ResourceModel\Order\Status\History\CollectionFactory;

class Fintecture extends AbstractHelper
{
    private const PAYMENT_COMMUNICATION = 'FINTECTURE-';

    /** @var Config */
    protected $config;

    /** @var CollectionFactory */
    protected $historyCollectionFactory;

    /** @var SearchCriteriaBuilder */
    protected $searchCriteriaBuilder;

    /** @var OrderRepositoryInterface */
    protected $orderRepository;

    /** @var TransactionRepositoryInterface */
    protected $transactionRepository;

    /** @var RemoteAddress */
    protected $remoteAddress;

    /** @var FintectureLogger */
    protected $fintectureLogger;

    public function __construct(
        Context $context,
        Config $config,
        CollectionFactory $historyCollectionFactory,
        OrderRepositoryInterface $orderRepository,
        TransactionRepositoryInterface $transactionRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        RemoteAddress $remoteAddress,
        FintectureLogger $fintectureLogger
    ) {
        $this->config = $config;
        $this->historyCollectionFactory = $historyCollectionFactory;
        $this->orderRepository = $orderRepository;
        $this->transactionRepository = $transactionRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->remoteAddress = $remoteAddress;
        $this->fintectureLogger = $fintectureLogger;

        parent::__construct($context);
    }

    public function getResponseUrl(): string
    {
        return $this->getUrl('fintecture/standard/response');
    }

    public function getOriginUrl(): string
    {
        return $this->getUrl('fintecture/standard/response');
    }

    public function getQrCodeUrl(): string
    {
        return $this->getUrl('fintecture/standard/qrcode');
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('fintecture/standard/send');
    }

    public function getUrl(string $route, array $params = []): string
    {
        return rtrim($this->_getUrl($route, $params), '/');
    }

    public function getOrderByIncrementId(string $incrementId): ?Order
    {
        $searchCriteria = $this->searchCriteriaBuilder->addFilter('increment_id', $incrementId)->create();
        $orderList = $this->orderRepository->getList($searchCriteria)->getItems();
        /** @var Order|null $order */
        $order = array_pop($orderList);

        return $order;
    }

    public function getSessionIdByOrderId(string $orderId): ?string
    {
        $searchCriteria = $this->searchCriteriaBuilder->addFilter('order_id', $orderId)->create();
        $transactionList = $this->transactionRepository->getList($searchCriteria)->getItems();
        /** @var Transaction|null $transaction */
        $transaction = array_pop($transactionList);
        if ($transaction) {
            $extraInfos = $transaction->getAdditionalInformation(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS);
            if ($extraInfos && isset($extraInfos['sessionId'])) {
                return $extraInfos['sessionId'];
            }
        }

        return null;
    }

    /**
     * Get Magento statuses associated with our params
     *
     * @return array|null
     */
    public function getOrderStatus(array $params)
    {
        // Mapping by payment_status
        $statusMapping = [
            'payment_created' => [
                'status' => $this->config->getPaymentCreatedStatus(),
                'state' => Order::STATE_PROCESSING,
            ],
            'order_created' => [
                'status' => $this->config->getOrderCreatedStatus(),
                'state' => Order::STATE_PROCESSING,
            ],
            'payment_pending' => [
                'status' => $this->config->getPaymentPendingStatus(),
                'state' => Order::STATE_PENDING_PAYMENT,
            ],
            'payment_partial' => [
                'status' => $this->config->getPaymentPartialStatus(),
                'state' => Order::STATE_NEW,
            ],
            'payment_unsuccessful' => [
                'status' => $this->config->getPaymentFailedStatus(),
                'state' => Order::STATE_CANCELED,
            ],
            'payment_error' => [
                'status' => $this->config->getPaymentFailedStatus(),
                'state' => Order::STATE_CANCELED,
            ],
            'payment_expired' => [
                'status' => $this->config->getPaymentFailedStatus(),
                'state' => Order::STATE_CANCELED,
            ],
            'sca_required' => [
                'status' => $this->config->getPaymentFailedStatus(),
                'state' => Order::STATE_CANCELED,
            ],
            'provider_required' => [
                'status' => $this->config->getPaymentFailedStatus(),
                'state' => Order::STATE_CANCELED,
            ],
        ];

        // Mapping by transfer_state
        if ($params['transferState'] === 'overpaid') {
            $statusMapping['payment_created'] = [
                'status' => $this->config->getPaymentOverpaidStatus(),
                'state' => Order::STATE_PROCESSING,
            ];
        }

        if (isset($statusMapping[$params['status']])) {
            return $statusMapping[$params['status']];
        }

        return null;
    }

    /**
     * Get an history comment associated with our params
     */
    public function getHistoryComment(array $params, bool $webhook = false): string
    {
        // Mapping by payment_status
        $notesMapping = [
            'payment_created' => __('The payment has been validated by the bank.'),
            'order_created' => __('The order is confirmed, you will receive the funds under 30 days.'),
            'payment_pending' => __('The bank is validating the payment.'),
            'payment_partial' => __('A partial payment has been made.'),
            'payment_unsuccessful' => __('The payment was rejected by either the payer or the bank.'),
            'payment_error' => __('The payment has failed for technical reasons.'),
            'sca_required' => __('The payer got redirected to their bank and needs to authenticate.'),
            'provider_required' => __('The payment has been dropped by the payer.'),
            'payment_expired' => __('The payment link has expired.'),
        ];

        // Mapping by transfer_state
        if ($params['transferState'] === 'overpaid') {
            $notesMapping['payment_created'] = __('The payment has been completed with a higher amount.');
        }

        if (isset($notesMapping[$params['status']])) {
            $note = $notesMapping[$params['status']];
        } else {
            $note = __('Unhandled status.');
        }

        $note = $webhook ? 'Webhook: ' . $note->render() : $note->render();

        return $note;
    }

    public function isStatusInHistory(Order $order, string $status): bool
    {
        $historyCollection = $this->historyCollectionFactory->create();
        $historyCollection->addFieldToFilter('parent_id', $order->getEntityId());
        if ($historyCollection->count() > 0) {
            /** @var History $history */
            foreach ($historyCollection->getItems() as $history) {
                if ($status === $history->getStatus()) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isStatusAlreadyFinal(Order $order): bool
    {
        return $this->isStatusInHistory($order, $this->config->getPaymentCreatedStatus())
            || $this->isStatusInHistory($order, $this->config->getPaymentOverpaidStatus());
    }

    public function generatePayload(Order $order, string $type, string $method = ''): array
    {
        $payload = [];

        $billingAddress = $order->getBillingAddress();
        if (!$billingAddress) {
            throw new \Exception('No billing address');
        }

        $phone = $billingAddress->getTelephone();
        if (strlen($phone) > 1 && substr($phone, 0, 1) === '0') {
            $phone = substr($phone, 1);
        }

        $name = $billingAddress->getFirstname();
        $lastName = $billingAddress->getLastname();
        if ($lastName) {
            $name .= ' ' . $lastName;
        }

        $street = $billingAddress->getStreet();
        if ($street) {
            $street = implode(' ', $street);
        }

        $payload = [
            'meta' => [
                'type' => $type,
                'psu_name' => $name,
                'psu_email' => $billingAddress->getEmail(),
                'psu_company' => $billingAddress->getCompany(),
                // 'psu_vat' => $billingAddress->getVatId(),
                'psu_phone' => $phone,
                'psu_phone_prefix' => '+33',
                'psu_ip' => $this->remoteAddress->getRemoteAddress(),
                'psu_address' => [
                    'street' => $street,
                    'zip' => $billingAddress->getPostcode(),
                    'city' => $billingAddress->getCity(),
                    'country' => $billingAddress->getCountryId(),
                ],
            ],
            'data' => [
                'type' => 'payments',
                'attributes' => [
                    'amount' => (string) round((float) $order->getBaseGrandTotal(), 2),
                    'currency' => $order->getOrderCurrencyCode(),
                    'communication' => self::PAYMENT_COMMUNICATION . $order->getId(),
                ],
            ],
        ];

        // Handle order expiration if enabled
        if ($this->config->isExpirationActive()) {
            $minutes = $this->config->getExpirationAfter();
            if (is_int($minutes) && $minutes >= 3 && $minutes <= 9999) {
                $payload['meta']['expiry'] = $minutes * 60;
            } else {
                $this->fintectureLogger->error('Payload', [
                    'message' => 'Expiration time must be between 3 and 9999 minutes.',
                    'minutes' => 'Current expiration time: ' . $minutes,
                ]);
            }
        }

        // Handle method for RTP
        if ($type === 'REQUEST_TO_PAY' && !empty($method)) {
            $payload['meta']['method'] = $method;
        }

        // Handle custom reconciliation field if enabled
        if ($this->config->isCustomReconciliationFieldActive() && $this->config->getCustomReconciliationField()) {
            $customReconciliationField = $this->config->getCustomReconciliationField();

            $payload['meta']['reconciliation'] = [
                'level' => 'key',
                'key' => $order->getData($customReconciliationField),
                'match_amount' => true,
            ];
        }

        return $payload;
    }
}
