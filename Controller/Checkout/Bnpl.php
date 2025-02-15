<?php

declare(strict_types=1);

namespace Fintecture\Payment\Controller\Checkout;

use Fintecture\Payment\Controller\FintectureAbstract;

class Bnpl extends FintectureAbstract
{
    public function execute()
    {
        if (!$this->sdk->isPisClientInstantiated()) {
            throw new \Exception('PISClient not instantiated');
        }

        try {
            $order = $this->getOrder();
            if (!$order) {
                throw new \Exception('No order found');
            }

            // Connect
            $data = $this->fintectureHelper->generatePayload($order, self::BNPL_TYPE);
            $apiResponse = $this->connect->get($order, $data);
            $url = $apiResponse->meta->url ?? '';

            if ($url) {
                return $this->resultRedirect->create()->setPath($url);
            } else {
                throw new \Exception('No url');
            }
        } catch (\Exception $e) {
            $this->fintectureLogger->error('Checkout BNPL', [
                'message' => 'Error building redirect URL',
                'orderIncrementId' => $order ? $order->getIncrementId() : null,
                'exception' => $e,
            ]);

            $this->restoreQuote($order);

            $errorMsg = __('A problem occurred during the payment initiation with Fintecture. Please try again or choose another payment method.');
            $this->messageManager->addErrorMessage($errorMsg->render());

            $url = $this->urlInterface->getUrl('checkout/cart/index');

            return $this->resultRedirect->create()->setPath($url);
        }
    }
}
