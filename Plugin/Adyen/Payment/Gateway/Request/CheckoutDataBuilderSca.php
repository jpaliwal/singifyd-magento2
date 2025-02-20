<?php

namespace Signifyd\Connect\Plugin\Adyen\Payment\Gateway\Request;

use Adyen\Payment\Gateway\Request\CheckoutDataBuilder as AdyenCheckoutDataBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Registry;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResourceModel;
use Magento\Store\Model\StoreManagerInterface;
use Signifyd\Connect\Helper\ConfigHelper;
use Signifyd\Connect\Logger\Logger;
use Signifyd\Connect\Model\CasedataFactory;
use Signifyd\Connect\Model\ResourceModel\Casedata as CasedataResourceModel;
use Signifyd\Connect\Model\ScaPreAuth\ScaEvaluation;

class CheckoutDataBuilderSca
{
    /**
     * @var QuoteResourceModel
     */
    protected $quoteResourceModel;

    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;

    /**
     * @var int
     */
    protected $quoteId;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var ConfigHelper
     */
    protected $configHelper;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManagerInterface;

    /**
     * @var ScaEvaluation
     */
    protected $scaEvaluation;

    /**
     * @var CasedataFactory
     */
    protected $casedataFactory;

    /**
     * @var CasedataResourceModel
     */
    protected $casedataResourceModel;

    /**
     * @var Registry
     */
    protected $registry;

    /**
     * CheckoutDataBuilder constructor.
     * @param QuoteResourceModel $quoteResourceModel
     * @param QuoteFactory $quoteFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param ConfigHelper $configHelper
     * @param Logger $logger
     * @param StoreManagerInterface $storeManagerInterface
     * @param ScaEvaluation $scaEvaluation
     * @param CasedataFactory $casedataFactory
     * @param CasedataResourceModel $casedataResourceModel
     * @param Registry $registry
     */
    public function __construct(
        QuoteResourceModel $quoteResourceModel,
        QuoteFactory $quoteFactory,
        ScopeConfigInterface $scopeConfig,
        ConfigHelper $configHelper,
        Logger $logger,
        StoreManagerInterface $storeManagerInterface,
        ScaEvaluation $scaEvaluation,
        CasedataFactory $casedataFactory,
        CasedataResourceModel $casedataResourceModel,
        Registry $registry
    ) {
        $this->quoteResourceModel = $quoteResourceModel;
        $this->quoteFactory = $quoteFactory;
        $this->scopeConfig = $scopeConfig;
        $this->configHelper = $configHelper;
        $this->logger = $logger;
        $this->storeManagerInterface = $storeManagerInterface;
        $this->scaEvaluation = $scaEvaluation;
        $this->casedataFactory = $casedataFactory;
        $this->casedataResourceModel = $casedataResourceModel;
        $this->registry = $registry;
    }

    public function beforeBuild(AdyenCheckoutDataBuilder $subject, array $buildSubject)
    {
        /** @var \Magento\Payment\Gateway\Data\PaymentDataObject $paymentDataObject */
        $paymentDataObject = \Magento\Payment\Gateway\Helper\SubjectReader::readPayment($buildSubject);
        $payment = $paymentDataObject->getPayment();
        /** @var \Magento\Sales\Model\Order $order */
        $this->quoteId = $payment->getOrder()->getQuoteId();
    }

    public function afterBuild(AdyenCheckoutDataBuilder $subject, $request)
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->quoteFactory->create();
        $this->quoteResourceModel->load($quote, $this->quoteId);

        $scaEvaluation = $this->scaEvaluation->getScaEvaluation($quote);

        if ($scaEvaluation instanceof \Signifyd\Models\ScaEvaluation === false) {
            return $request;
        }

        $executeThreeD = null;
        $scaExemption = null;

        switch ($scaEvaluation->outcome) {
            case 'REQUEST_EXEMPTION':
                $this->logger->info("Signifyd's recommendation is to request exemption");

                $placement = $scaEvaluation->exemptionDetails->placement;

                if ($placement === 'AUTHENTICATION') {
                    $executeThreeD = 'True';
                    $scaExemption = 'tra';
                } elseif ($placement === 'AUTHORIZATION') {
                    $executeThreeD = 'False';
                    $scaExemption = 'tra';
                }
                break;

            case 'REQUEST_EXCLUSION':
            case 'DELEGATE_TO_PSP':
                $recommendation = $scaEvaluation->outcome == 'DELEGATE_TO_PSP' ?
                    'no exemption/exclusion identified' : 'exclusion';

                $this->logger->info("Signifyd's recommendation is {$recommendation}");

                $executeThreeD = '';
                $scaExemption = '';
                break;

            case 'SOFT_DECLINE':
                $this->logger->info("Processor responds back with a soft decline");

                $executeThreeD = 'True';
                $scaExemption = '';
                break;
        }

        if (isset($executeThreeD) && isset($scaExemption)) {
            $request['body']['additionalData']['executeThreeD'] = $executeThreeD;
            $request['body']['additionalData']['scaExemption'] = $scaExemption;
        }

        return $request;
    }
}
