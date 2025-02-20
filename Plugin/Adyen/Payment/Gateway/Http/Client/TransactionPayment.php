<?php

namespace Signifyd\Connect\Plugin\Adyen\Payment\Gateway\Http\Client;

use Adyen\Payment\Gateway\Http\Client\TransactionPayment as AdyenTransactionPayment;
use Magento\Store\Model\StoreManagerInterface;
use Signifyd\Connect\Logger\Logger;
use Signifyd\Connect\Model\ScaPreAuth\ScaEvaluation;
use Signifyd\Connect\Model\ScaPreAuth\ScaEvaluationConfig;

class TransactionPayment
{
    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var ScaEvaluation
     */
    protected $scaEvaluation;

    /**
     * @var ScaEvaluationConfig
     */
    protected $scaEvaluationConfig;

    /**
     * @param StoreManagerInterface $storeManager
     * @param Logger $logger
     * @param ScaEvaluation $scaEvaluation
     * @param ScaEvaluationConfig $scaEvaluationConfig
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        Logger $logger,
        ScaEvaluation $scaEvaluation,
        ScaEvaluationConfig $scaEvaluationConfig
    ) {
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->scaEvaluation = $scaEvaluation;
        $this->scaEvaluationConfig = $scaEvaluationConfig;
    }

    public function afterPlaceRequest(AdyenTransactionPayment $subject, $response)
    {
        $storeId = $this->storeManager->getStore()->getId();
        $isScaEnabled = $this->scaEvaluationConfig->isScaEnabled($storeId, 'adyen_cc');

        if ($isScaEnabled === false) {
            return $response;
        }

        if (isset($response['refusalReasonCode']) && (int)$response['refusalReasonCode'] === 38) {
            $this->logger->info("Registering adyen soft decline response");
            $this->scaEvaluation->setIsSoftDecline(true);
        } else {
            $this->scaEvaluation->setIsSoftDecline(false);
        }

        return $response;
    }
}
