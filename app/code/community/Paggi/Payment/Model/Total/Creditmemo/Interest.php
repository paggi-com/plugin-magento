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
class Paggi_Payment_Model_Total_Creditmemo_Interest
    extends Mage_Sales_Model_Order_Creditmemo_Total_Abstract
{

    protected $_code = 'paggi_interest';

    public function collect(Mage_Sales_Model_Order_Creditmemo $creditmemo)
    {
        $order = $creditmemo->getOrder();
        $amount = $order->getPaggiInterestAmount();
        $baseAmount = $order->getBasePaggiInterestAmount();
        if ($amount) {
            $creditmemo->setPaggiInterestAmount($amount);
            $creditmemo->setBasePaggiInterestAmount($baseAmount);
            $creditmemo->setGrandTotal($creditmemo->getGrandTotal() + $amount);
            $creditmemo->setBaseGrandTotal($creditmemo->getBaseGrandTotal() + $baseAmount);
        }

        return $this;
    }

}