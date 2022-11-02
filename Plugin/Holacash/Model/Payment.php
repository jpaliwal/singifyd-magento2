<?php

namespace Signifyd\Connect\Plugin\Holacash\Model;

use Signifyd\Connect\Helper\PurchaseHelper;
use Signifyd\Connect\Logger\Logger;
use Signifyd\Connect\Model\CasedataFactory;
use Signifyd\Connect\Model\ResourceModel\Casedata as CasedataResourceModel;
use Magento\Store\Model\StoreManagerInterface;
use Holacash\Payment\Model\PaymentMethod as HolacashPayment;
use Magento\Checkout\Model\Cart as CheckoutCart;

class Payment
{
    /**
     * @var CasedataFactory
     */
    protected $casedataFactory;

    /**
     * @var CasedataResourceModel
     */
    protected $casedataResourceModel;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var PurchaseHelper
     */
    protected $purchaseHelper;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var CheckoutCart
     */
    protected $checkoutCart;

    /**
     * CheckoutPaymentsDetailsHandler constructor.
     *
     * @param CasedataFactory       $casedataFactory
     * @param CasedataResourceModel $casedataResourceModel
     * @param Logger                $logger
     * @param PurchaseHelper        $purchaseHelper
     * @param StoreManagerInterface $storeManager
     * @param CheckoutCart          $checkoutCart
     */
    public function __construct(
        CasedataFactory       $casedataFactory,
        CasedataResourceModel $casedataResourceModel,
        Logger                $logger,
        PurchaseHelper        $purchaseHelper,
        StoreManagerInterface $storeManager,
        CheckoutCart $checkoutCart
    ) {
        $this->casedataFactory = $casedataFactory;
        $this->casedataResourceModel = $casedataResourceModel;
        $this->logger = $logger;
        $this->purchaseHelper = $purchaseHelper;
        $this->storeManager = $storeManager;
        $this->checkoutCart = $checkoutCart;
    }

    /**
     * @param  HolacashPayment $subject
     * @param  $response
     * @return null
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function beforeError(HolacashPayment $subject, $response)
    {
        $policyName = $this->purchaseHelper->getPolicyName(
            \Magento\Store\Model\ScopeInterface::SCOPE_STORES,
            $this->storeManager->getStore()->getId()
        );

        $isPreAuth = $this->purchaseHelper->getIsPreAuth($policyName, 'holacash');

        $quote = $this->checkoutCart->getQuote();

        if ($isPreAuth === false || isset($quote) === false) {
            return null;
        }

        $quoteId = $quote->getId();
        /**
         * @var $case \Signifyd\Connect\Model\Casedata
        */
        $case = $this->casedataFactory->create();
        $this->casedataResourceModel->load($case, $quoteId, 'quote_id');

        if ($case->isEmpty()) {
            return null;
        }

        $message = $response["detail"]["message"] ?? 'Could not create charge';
        $code = $response["detail"]["code"] ?? '';

        switch ($code) {
            case 'new_cvv_required':
            case 'invalid_security_credentials':
            case 'credit_card_validation_code_required':
            case 'credit_card_validation_code_invalid':
                $signifydReason = 'INVALID_CVC';
                break;
            case 'blocked_by_hola_cash_fraud_detection':
            case 'invalid_anti_fraud_header_multiple':
            case 'invalid_anti_fraud_header_format':
            case 'invalid_anti_fraud_header_missing_ip_address':
            case 'invalid_anti_fraud_header_missing_device_id':
            case 'invalid_anti_fraud_header_missing_user_timezone':
            case 'invalid_anti_fraud_header_invalid_user_timezone':
            case 'invalid_anti_fraud_header_invalid_ip_address':
            case 'three_ds_authentication_failed':
            case 'credit_card_fraud_detected':
            case '3D_authentication_failed':
                $signifydReason = 'FRAUD_DECLINE';
                break;
            case 'bank_account_clabe_unrecognizable':
            case 'bank_account_clabe_already_exists':
            case 'bank_account_clabe_associated_with_hola_cash':
            case 'bank_account_clabe_not_supported_spei_with_hola_cash':
                $signifydReason = 'INCORRECT_NUMBER';
                break;
            case 'transaction_pin_required':
            case 'credit_card_purchase_not_supported':
            case 'credit_card_not_supported_on_line':
            case 'credit_card_call_bank_to_authorize':
            case 'card_declined':
                $signifydReason = 'CARD_DECLINED';
                break;
            case 'credit_card_expired_year':
            case 'credit_card_expired_month':
            case 'credit_card_expired':
            case 'invalid_card_expiry':
                $signifydReason = 'INVALID_EXPIRY_DATE';
                break;
            case 'incorrect_pin':
                $signifydReason = 'INCORRECT_CVC';
                break;
            case 'credit_card_number_invalid':
            case 'payment_token_not_valid':
            case 'payment_token_alias_not_valid':
                $signifydReason = 'INCORRECT_NUMBER';
                break;
            case 'test_credit_card':
                $signifydReason = 'TEST_CARD_DECLINE';
                break;
            case 'credit_card_insufficient_funds':
                $signifydReason = 'INSUFFICIENT_FUNDS';
                break;
            case 'credit_card_stolen':
            case 'credit_card_lost':
                $signifydReason = 'STOLEN_CARD';
                break;
            case 'credit_card_restricted_by_bank':
                $signifydReason = 'RESTRICTED_CARD';
                break;
            case 'credit_card_hold_card':
                $signifydReason = 'PICK_UP_CARD';
                break;
            case 'create_charge_invalid_phone_number':
                $signifydReason = 'INVALID_NUMBER';
                break;

            default:
                $signifydReason = 'PROCESSING_ERROR';
                break;
        }

        if ($case->getEntries('HolaCashRefusedReason') == $signifydReason) {
            $this->logger->info("Reason already send");
            return null;
        }

        $holaCashData = [];
        $holaCashData['gatewayRefusedReason'] = $signifydReason;
        $holaCashData['gatewayStatusMessage'] = $message;
        $holaCashData['gateway'] = 'holacash';

        $case->setEntries("HolaCashRefusedReason", $signifydReason);
        $this->casedataResourceModel->save($case);

        $transaction = $this->purchaseHelper->makeCheckoutTransactions(
            $quote,
            $case->getCheckoutToken(),
            $holaCashData
        );

        $this->purchaseHelper->postTransactionToSignifyd($transaction, $quote);
        return null;
    }
}
