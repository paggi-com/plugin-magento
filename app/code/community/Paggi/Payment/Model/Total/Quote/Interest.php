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
class Paggi_Payment_Model_Total_Quote_Interest
    extends Mage_Sales_Model_Quote_Address_Total_Abstract
{

    protected $_helper;
    protected $_code = 'paggi_interest';

    public function __construct()
    {
        $this->setCode($this->_code);
    }

    public function collect(Mage_Sales_Model_Quote_Address $address)
    {
        parent::collect($address);

        $this->_setAmount(0);
        $this->_setBaseAmount(0);

        $items = $this->_getAddressItems($address);
        if (!count($items)) {
            return $this;
        }

        $interestAmount = 0;
        $quote = $address->getQuote();

        $payment = Mage::app()->getRequest()->getPost('payment');
        if (
            $address->getQuote()->getPayment()->getMethod() == 'paggi_cc'
        ) {
            if (isset($payment['installments']) && $payment['installments'] > 1) {
                $installments = $payment['installments'];
                $grandTotal = $payment['base_grand_total'];
                $installmentsValue = $this->_getHelper()->getInstallmentValue($grandTotal, $installments);
                $totalOrderWithInterest = $installmentsValue * $installments;
                $interestAmount = $totalOrderWithInterest - $grandTotal;
            }

            $address->setPaggiInterestAmount($interestAmount);
            $address->setBasePaggiInterestAmount($interestAmount);
            $quote->setPaggiInterestAmount($interestAmount);
            $quote->setBasePaggiInterestAmount($interestAmount);

        }

        $this->_addAmount($interestAmount);
        $this->_addBaseAmount($interestAmount);

        return $this;
    }

    public function fetch(Mage_Sales_Model_Quote_Address $address)
    {
        $amount = $address->getPaggiInterestAmount();
        if ($amount > 0) {
            $address->addTotal(array(
                'code' => $this->getCode(),
                'title' => Mage::helper('paggi')->__('Interest'),
                'value' => $amount
            ));
        }

        return $this;
    }

    /**
     * @return Paggi_Payment_Helper_Data|Mage_Core_Helper_Abstract
     */
    protected function _getHelper()
    {
        if (!$this->_helper) {
            $this->_helper = Mage::helper('paggi');
        }

        return $this->_helper;
    }
}