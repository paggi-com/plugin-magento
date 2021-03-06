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
class Paggi_Payment_CcController extends Mage_Core_Controller_Front_Action
{
    /**
     * Order instance
     */
    protected $_order;
    protected $_api;
    protected $errorMessagePaggi = '';
    protected $errorCodePaggi = '';
    protected $errorTypeErrorPaggi = '';
    
    protected function _getSession()
    {
    	return Mage::getSingleton('adminhtml/session');
    }
    
    public function saveAction()
    {             
       $this->loadLayout();
       $this->renderLayout();       
    }
    
    public function editAction()
    {
       $this->loadLayout();
       $this->renderLayout();
    }

    /**
     * List all saved credit cards at the store
     */
    public function indexAction()
    {
        $this->loadLayout();
        $this->_initLayoutMessages('catalog/session');

        if ($navigationBlock = $this->getLayout()->getBlock('customer_account_navigation')) {
            $navigationBlock->setActive('paggi/cc');
        }
        if ($block = $this->getLayout()->getBlock('paggi_cc_index')) {
            $block->setRefererUrl($this->_getRefererUrl());
        }
        $this->getLayout()->getBlock('head')->setTitle($this->__('My Saved Cards'));

        $this->renderLayout();
    }

    /**
     * Method that removes a saved credit card at the store and also at Paggi
     */
    public function deleteAction()
    {
        try {
            $cardId = (int)$this->getRequest()->getParam('id');

            if ($cardId) {
                $customerId = Mage::getSingleton('customer/session')->getCustomerId();
                /** @var Paggi_Payment_Model_Card $card */
                $card = Mage::getModel('paggi/card')->load($cardId);
                if ($card->getCustomerId() == $customerId) {
                    $card->delete();

                    if ($this->getApi()->deleteCC($card->getToken())) {
                        Mage::getSingleton('core/session')->addNotice($this->__('Credit Card removed successfully'));
                    }
                }
            }
        } catch (Exception $e) {
            Mage::logException($e);
            Mage::getSingleton('core/session')->addNotice($this->__('There\'s was an error removing the credit card at Paggi'));
        }

        $this->_redirectReferer();

    }

    public function removeCardAction()
    {
        $response = array('code' => 500, 'response' => 'error');
        $customerId = $this->getRequest()->getParam('custId');

        try {
            /** @var Mage_Customer_Model_Session $session */
            $session = Mage::getSingleton('customer/session');
            $currentCustomer = $session->getCustomer();
            if ($currentCustomer && $customerId == $currentCustomer->getId()) {
                $cardId = $this->getRequest()->getParam('cId');
                if ($cardId) {
                    $collection = Mage::getResourceModel('paggi/card_collection')
                        ->addFieldToFilter('customer_id', $customerId)
                        ->addFieldToFilter('entity_id', $cardId);
                    if ($collection->getSize() > 0) {
                        foreach ($collection as $item) {
                            $item->delete();
                        }
                        $response = array('code' => 200, 'response' => 'success');
                    }
                }
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }

        $this->getResponse()->setHttpResponseCode($response['code']);
        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody(json_encode($response));

    }

    /**
     * @return Paggi_Payment_Model_Api|Mage_Core_Model_Abstract
     */
    public function getApi()
    {
        if (!$this->_api) {
            $this->_api = Mage::getModel('paggi/api');
        }

        return $this->_api;
    }
}