<?php

/**
 * Bizcommerce Desenvolvimento de Plataformas Digitais Ltda - Epp
 *
 * INFORMAÇÕES SOBRE LICENÇA
 *
 * Open Software License (OSL 3.0).
 * http://opensource.org/licenses/osl-3.0.php
 *
 * DISCLAIMER
 *
 * Não edite este arquivo caso você pretenda atualizar este módulo futuramente
 * para novas versões.
 *
 * @category      Paggi
 * @package       Paggi_Payment
 * @author        Thiago Contardi <thiago@contardi.com.br>
 *
 * @license       http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *
 */
class Paggi_Payment_Model_Method_Cc
    extends Mage_Payment_Model_Method_Abstract
    implements Mage_Payment_Model_Recurring_Profile_MethodInterface
{
    /**
     * unique internal payment method identifier
     * @var string [a-z0-9_]
     */
    protected $_code = 'paggi_cc';
    protected $_canSaveCc = true;
    protected $_canManageRecurringProfiles = true;

    protected $_formBlockType = 'paggi/form_cc';
    protected $_infoBlockType = 'paggi/info_cc';

    protected $_helper;

    /**
     * @param mixed $data
     * @return $this
     */
    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }

        /** @var Mage_Payment_Model_Info $info */
        $info = $this->getInfoInstance();

        $ccCid = preg_replace("/[^0-9]/", '', $data->getCcCid());
        $installments = $data->getInstallments();
        $useSavedCard = $data->getUseSavedCard();
        $grandTotal = $data->getBaseGrandTotal();
        $hasInterest = false;

        $cpfCnpj = $data->getCpfCnpj();
        if (!$this->getHelper()->getConfig('show_taxvat_field')) {
            $cpfCnpj = $this->getHelper()->getTaxvatValue();
        }

        if ($useSavedCard) {
            /** @var Paggi_Payment_Model_Card $card */
            $card = $this->getHelper()->getSavedCard($data->getCcToken());
            $info->setAdditionalInformation('cc_token', $card->getToken());
            $info->setAdditionalInformation('cc_description', $card->getDescription());
            $info->setAdditionalInformation('cc_customer_id_paggi', $card->getCustomerIdPaggi());
            $ccType = $card->getBrand();
            $ccCid = $data->getCcCidSc();
        } else {

            $ccType = $data->getCcType();
            $ccOwner = $data->getCcOwner();
            $ccNumber = preg_replace("/[^0-9]/", '', $data->getCcNumber());
            $ccNumberEnc = $info->encrypt($ccNumber);
            $ccLast4 = substr($ccNumber, -4);
            $ccExpMonth = str_pad($data->getCcExpMonth(), 2, '0', STR_PAD_LEFT);
            $ccExpYear = $data->getCcExpYear();
            $saveCard = $data->getSaveCard();

            $info->setCcOwner($ccOwner);
            $info->setCcNumber($ccNumber);
            $info->setCcExpMonth($ccExpMonth);
            $info->setCcExpYear($ccExpYear);
            $info->setCcNumberEnc($ccNumberEnc);
            $info->setCcLast4($ccLast4);

            $info->setAdditionalInformation('cc_token', false);
            $info->setAdditionalInformation('cc_description', false);
            $info->setAdditionalInformation('cc_customer_id_paggi', false);
            $info->setAdditionalInformation('cc_save_card', $saveCard);
        }

        $info->setAdditionalInformation('cpf_cnpj', $cpfCnpj);
        $interestRate = $this->getHelper()->getConfig('interest_rate', $this->getCode());
        $installmentsWithoutInterest = $this->getHelper()->getConfig('installments_without_interest_rate', $this->getCode());
        if ($installmentsWithoutInterest >= $installments) {
            $interestRate = null;
        }

        if ($installments > 1) {
            $installmentsValue = $this->getHelper()->getInstallmentValue($grandTotal, $installments);
            $totalOrderWithInterest = $installmentsValue * $installments;
            $interestValue = $totalOrderWithInterest - $grandTotal;
            $info->setAdditionalInformation('cc_interest_amount', $interestValue);
            $info->setAdditionalInformation('cc_total_with_interest', $totalOrderWithInterest);
            $info->setAdditionalInformation('cc_interest_value', $this->getHelper()->getInstallmentValue($grandTotal, $installments));
        }

        $info->setAdditionalInformation('cc_has_interest', ($interestRate) ? true : false);
        $info->setAdditionalInformation('cc_interest_rate', $interestRate);
        $info->setAdditionalInformation('cc_installment_value', $this->getHelper()->getInstallmentValue($grandTotal, $installments));
        $info->setAdditionalInformation('cc_installments', $installments);
        $info->setAdditionalInformation('installments', $installments);
        $info->setAdditionalInformation('base_grand_total', $grandTotal);

        $info->setCcType($ccType);
        $info->setCcInstallments($installments);

        Mage::unregister('paggi_cc_cid');
        Mage::register('paggi_cc_cid', $ccCid);

        return $this;
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param float $amount
     * @return $this
     * @throws Mage_Core_Exception
     */
    public function order(Varien_Object $payment, $amount)
    {
        $errors = null;
        try {
            /** @var Paggi_Payment_Model_Api $api */
            $api = $this->getHelper()->getApi();
            $response = $api->ccMethod($this, $payment, $amount);
            if ($response) {
                $responseData = Mage::helper('core')->jsonDecode($response->getBody(), false);

                if (property_exists($responseData, 'id')) {

                    $tid = $responseData->id;
                    $payment->setCcTransId($tid);
                    $payment->setLastTransId($tid);

                    $payment = $this->setAdditionalInfo($payment, $responseData);

                    if ($this->getHelper()->getIsDeniedState($responseData->status)) {

                        if ($this->getHelper()->getConfig('stop_processing')) {
                            $errors = $this->getHelper()->__('The transaction wasn\'t authorized by the issuer, please check your data and try again');
                            Mage::throwException($errors);
                        }
                        $payment->setSkipOrderProcessing(true);
                    }
                } else {
                    Mage::throwException(Mage::helper('payment')->__('There was an error processing your request. Please contact us or try again later.'));
                }
            } else {
                Mage::throwException(Mage::helper('payment')->__('There was an error processing your request. Please contact us or try again later.'));
            }

        } catch (Exception $e) {
            Mage::getSingleton('checkout/session')->getQuote()->setReservedOrderId(null);
            Mage::logException($e);
            $this->getHelper()->log($e->getMessage());
            $exception = $errors ?: Mage::helper('payment')->__('There was an error processing your request. Please contact us or try again later.');
            Mage::throwException($exception);
        }

        return $this;
    }


    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @return Mage_Sales_Model_Order_Payment
     */
    protected function setAdditionalInfo(Mage_Sales_Model_Order_Payment $payment, $response)
    {
        //Set Transaction Id
        if (property_exists($response, 'id')) {
            $paggiOrderId = $response->id;
            $payment->setAdditionalInformation('order_id', $paggiOrderId);
        }

        if (property_exists($response, 'external_identifier')) {
            $payment->setAdditionalInformation('external_identifier', $response->external_identifier);
        }

        if (property_exists($response, 'charges')) {

            foreach ($response->charges as $i => $charge) {
                $tid = $charge->id;
                if ($i == 0) {
                    $payment->setCcTransId($tid);
                    $payment->setTransactionId($tid);
                    $payment->setAdditionalInformation('transaction_id', $tid);
                }

                $payment->setAdditionalInformation('charge_id_' . $i , $tid);
                $payment->setAdditionalInformation('charge_amount_' . $i , $charge->amount);
                $payment->setLastTransId($tid);
            }
        }

        return $payment;
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param float $amount
     * @return Mage_Payment_Model_Method_Abstract
     */
    public function capture(Varien_Object $payment, $amount)
    {
        if (!$payment->getAdditionalInformation('recurring_profile')) {

            if (!$amount) {
                Mage::throwException($this->getHelper()->__('There was an error capturing your order at Paggi'));
            }

            /** @var Mage_Sales_Model_Order $order */
            $order = $payment->getOrder();
            $captured = $payment->getAdditionalInformation('captured');

            if ($order->getGrandTotal() != $captured) {
                Mage::throwException($this->getHelper()->__('Order already captured partially, the left amount was cancelled at the processor'));
            }

            if ($payment->canCapture()) {
                $paggiOrderId = $payment->getAdditionalInformation('order_id');
                $hasInterest = $payment->getAdditionalInformation('cc_has_interest');
                if ($hasInterest) {
                    $amount = $payment->getAdditionalInformation('cc_total_with_interest');
                }

                /** @var Paggi_Payment_Model_Api $this ->getOrderModel() */
                $response = $this->getHelper()->getApi()->capture($order, $paggiOrderId, $amount);


                //@TODO
                if (isset($response['transactionID'])) {
                    $transactionId = $response['transactionID'];
                    $payment->setAdditionalInformation('captured', true);
                    $payment->setAdditionalInformation('captured_date', date('Y-m-d'));
                    $payment->setParentTransactionId($transactionId);
                    $payment->save();
                } else {
                    Mage::throwException($this->getHelper()->__('There was an error capturing your order at Paggi'));
                }
            }
        }
        return parent::capture($payment, $amount);
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @param float $amount
     * @return Mage_Payment_Model_Abstract
     */
    public function refund(Varien_Object $payment, $amount)
    {
        //@TODO
        if (!$payment->getAdditionalInformation('recurring_profile')) {
            /** @var Mage_Sales_Model_Order $order */
            $order = $payment->getOrder();
            if ($payment->canRefund()) {
                $hasInterest = $payment->getAdditionalInformation('cc_has_interest');
                if ($hasInterest) {
                    $amount = $payment->getAdditionalInformation('cc_total_with_interest');
                }
                $paggiOrderId = $payment->getAdditionalInformation('order_id');

                $captured = $payment->getAdditionalInformation('captured');
                $capturedDate = $payment->getAdditionalInformation('captured_date');
                $currentDate = new DateTime();
                $currentDate = $currentDate->format('Y-m-d');

                if ($captured && $capturedDate == $currentDate) {

                    $transactionId = $payment->getAdditionalInformation('transaction_id');
                    $this->getHelper()->getApi()->void($order, $transactionId);

                } else {
                    $this->getHelper()->getApi()->refund($order, $paggiOrderId, $amount);
                }
            }
        }
        return parent::refund($payment, $amount);
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @return Mage_Payment_Model_Abstract
     */
    public function void(Varien_Object $payment)
    {
        if (!$payment->getAdditionalInformation('recurring_profile')) {
            /** @var Mage_Sales_Model_Order $order */
            $order = $payment->getOrder();
            if ($payment->canVoid($payment)) {

                $transactionId = $payment->getAdditionalInformation('transaction_id');
                $this->getHelper()->getApi()->void($order, $transactionId);

            }
        }
        return parent::void($payment);
    }

    /**
     * @param Mage_Sales_Model_Order_Payment $payment
     * @return Mage_Payment_Model_Abstract
     */
    public function cancel(Varien_Object $payment)
    {
        if (!$payment->getAdditionalInformation('recurring_profile')) {
            /** @var Mage_Sales_Model_Order $order */
            $order = $payment->getOrder();
            $paggiOrderId = $payment->getAdditionalInformation('order_id');
            $this->getHelper()->getApi()->void($order, $paggiOrderId);
        }

        return parent::cancel($payment);
    }

    /**
     * Validate data
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     * @throws Mage_Core_Exception
     */
    public function validateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
    {

    }

    /**
     * Submit to the gateway
     *
     * @param Mage_Sales_Model_Recurring_Profile $profile
     * @param Mage_Payment_Model_Info $paymentInfo
     */
    public function submitRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile, Mage_Payment_Model_Info $paymentInfo)
    {
        $errors = null;
        try {
            /** @var Paggi_Payment_Model_Api $api */
            $api = $this->getHelper()->getApi();
            $response = $api->recurringMethod($this, $profile, $paymentInfo);

            if (isset($response['transactionID']) && $response['transactionID']) {

                if ($response['responseCode'] != 0 && $response['responseCode'] != 5){

                    $errors = $this->getHelper()->__('The transaction wasn\'t authorized by the issuer, please check your data and try again');
                    $profile->setState(Mage_Sales_Model_Recurring_Profile::STATE_CANCELED);
                    Mage::throwException($errors);

                } else {

                    if (isset($response['orderID'])) {

                        $profile->setData('reference_id', $response['orderID']);

                        if ($profile->getInitAmount()) {

                            $productItemInfo = new Varien_Object();
                            $productItemInfo->setPaymentType(Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_INITIAL);
                            $productItemInfo->setPrice($profile->getInitAmount());
                            $productItemInfo->setShippingAmount(0);
                            $productItemInfo->setTaxAmount(0);
                            $productItemInfo->setIsVirtual(1);

                            $order = $profile->createOrder($productItemInfo);
                            $order->save();
                            if ($order->getId()) {

                                /** @var Mage_Sales_Model_Order_Payment $payment */
                                $payment = $order->getPayment();
                                $this->setAdditionalInfo($payment, $response);

                                $profile->addOrderRelation($order->getId());
                                $this->_getOrderHelper()->setOrderPaymentData($profile, $paymentInfo, $order, $response);
                            }

                        }

                        $itemInfo = new Varien_Object();
                        $itemInfo->setData($profile->getOrderItemInfo());
                        $itemInfo->setPaymentType(Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_REGULAR);
                        $order = $profile->createOrder($itemInfo);
                        $order->save();

                        if ($order->getId()) {

                            /** @var Mage_Sales_Model_Order_Payment $payment */
                            $payment = $order->getPayment();
                            $this->setAdditionalInfo($payment, $response);

                            $profile->addOrderRelation($order->getId());
                            $this->_getOrderHelper()->setOrderPaymentData($profile, $paymentInfo, $order, $response);
                        }

                        $profile->setIsProfileActive(1)
                            ->setState(Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE);

                    }

                }
            } else {
                Mage::throwException(Mage::helper('payment')->__('There was an error processing your request. Please contact us or try again later.'));
            }

        } catch (Exception $e) {
            Mage::getSingleton('checkout/session')->getQuote()->setReservedOrderId(null);
            $this->getHelper()->log($e->getMessage());
            $exception = $errors ?: Mage::helper('payment')->__('There was an error processing your request. Please contact us or try again later.');
            Mage::throwException($exception);
        }

        return $this;
    }

    /**
     * Fetch details
     *
     * @param string $referenceId
     * @param Varien_Object $result
     */
    public function getRecurringProfileDetails($referenceId, Varien_Object $result)
    {
        /** @var Mage_Sales_Model_Recurring_Profile $profile */
        $profile = Mage::getModel('sales/recurring_profile')->load($referenceId, 'reference_id');

        $response = $this->getHelper()->getApi()->pullReportByOrderId($profile->getReferenceId());

        if (intval($response['errorCode']) != 0) {
            $errorMessage = $response['errorMsg'];
            $this->getHelper()->log($errorMessage);
            Mage::throwException($errorMessage);
        }

        $pageToken = isset($response['pageToken']) ? $response['pageToken'] : null;
        $pageNumber = isset($response['pageNumber']) ? (int) $response['pageNumber'] : 1;
        $numberOfPages = isset($response['numberOfPages']) ? $response['numberOfPages'] : 1;
        $totalNumberOfRecords = $response['totalNumberOfRecords'];

        if ($totalNumberOfRecords > 0) {
            foreach ($response['records'] as $record) {
                if ($record['recurringPaymentFlag'] == 1) {
                    $this->_getOrderHelper()->updateRecurringProfile($profile, $record);
                }
            }
            while ($pageNumber < $numberOfPages) {
                $pageNumber++;
                $response = $this->getHelper()->getApi()->pullReportByOrderId($profile->getReferenceId(), $pageToken, $pageNumber);

                foreach ($response['records'] as $record) {
                    if ($record['recurringPaymentFlag'] == 1) {
                        $this->_getOrderHelper()->updateRecurringProfile($profile, $record);
                    }
                }
            }
        }

        if ($profile->getState() == Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE) {
            $result->setIsProfileActive(true);
        } else if ($profile->getState() == Mage_Sales_Model_Recurring_Profile::STATE_SUSPENDED) {
            $result->setIsProfileSuspended(true);
        }
    }

    public function canManageRecurringProfiles()
    {
        return $this->getConfigData('use_recurring_profile');
    }

    /**
     * Check whether can get recurring profile details
     *
     * @return bool
     */
    public function canGetRecurringProfileDetails()
    {
        return true;
    }

    /**
     * Update data, it'll use updateRecurringProfileStatus
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfile(Mage_Payment_Model_Recurring_Profile $profile)
    {
        return $this;
    }

    /**
     * Manage status
     *
     * @param Mage_Payment_Model_Recurring_Profile $profile
     */
    public function updateRecurringProfileStatus(Mage_Payment_Model_Recurring_Profile $profile)
    {
        /** @var Paggi_Payment_Model_Api $api */
        $api = $this->getHelper()->getApi();
        $action = null;
        switch ($profile->getNewState()) {
            case Mage_Sales_Model_Recurring_Profile::STATE_CANCELED:
                if (!$api->cancelRecurring($profile)) {
                    Mage::throwException($this->getHelper()->__('There was an error while cancelling the recurring profile, please contact us'));
                }
                break;
            case Mage_Sales_Model_Recurring_Profile::STATE_SUSPENDED:
                if (!$api->updateRecurring($profile, false)) {
                    Mage::throwException($this->getHelper()->__('There was an error while cancelling the recurring profile, please contact us'));
                }
                break;
            case Mage_Sales_Model_Recurring_Profile::STATE_ACTIVE:
                if (!$api->updateRecurring($profile, true)) {
                    Mage::throwException($this->getHelper()->__('There was an error while cancelling the recurring profile, please contact us'));
                }
                break;
        }
    }

    /**
     * @return Paggi_Payment_Helper_Data
     */
    protected function getHelper()
    {
        if (!$this->_helper) {
            $this->_helper = Mage::helper('paggi');
        }
        return $this->_helper;
    }
}
