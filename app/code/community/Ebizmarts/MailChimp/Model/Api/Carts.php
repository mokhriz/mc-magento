<?php

/**
 * mailchimp-lib Magento Component
 *
 * @category Ebizmarts
 * @package mailchimp-lib
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class Ebizmarts_MailChimp_Model_Api_Carts
{

    const BATCH_LIMIT = 100;

    protected $firstDate;
    protected $counter;
    protected $batchId;
    protected $api = null;

    /**
     * @param $mailchimpStoreId
     * @return array
     */
    public function createBatchJson($mailchimpStoreId)
    {
        $allCarts = array();
        if(!Mage::getConfig(Ebizmarts_MailChimp_Model_Config::ABANDONEDCART_ACTIVE))
        {
            return $allCarts;
        }
        $this->firstDate = Mage::getStoreConfig(Ebizmarts_MailChimp_Model_Config::ABANDONEDCART_FIRSTDATE);
        $this->counter = 0;
        $this->batchId = Ebizmarts_MailChimp_Model_Config::IS_QUOTE.'_'.date('Y-m-d-H-i-s');
        // get all the carts converted in orders (must be deleted on mailchimp)
        $allCarts = array_merge($allCarts,$this->_getConvertedQuotes($mailchimpStoreId));
        // get all the carts modified but not converted in orders
        $allCarts = array_merge($allCarts,$this->_getModifiedQuotes($mailchimpStoreId));
        // get new carts
        $allCarts = array_merge($allCarts,$this->_getNewQuotes($mailchimpStoreId));
        return $allCarts;
    }

    /**
     * @param $mailchimpStoreId
     * @return array
     */
    protected function _getConvertedQuotes($mailchimpStoreId)
    {
        $allCarts = array();
        $convertedCarts = Mage::getModel('sales/quote')->getCollection();
        // get only the converted quotes
        $convertedCarts->addFieldToFilter('is_active',array('eq'=>0));
        // be sure that the quote are already in mailchimp
        $convertedCarts->addFieldToFilter('mailchimp_sync_delta',array(
            array('neq' => '0000-00-00 00:00:00'),
            array('null',false)
        ));
        // and not deleted
        $convertedCarts->addFieldToFilter('mailchimp_deleted',array('eq'=>0));
        $convertedCarts->addFieldToFilter('created_at',array('from'=>$this->firstDate));
        // limit the collection
        $convertedCarts->getSelect()->limit(self::BATCH_LIMIT);
        foreach($convertedCarts as $cart)
        {
                // we need to delete all the carts associated with this email
//                $allCartsForEmail = Mage::getModel('sales/quote')->getCollection();
//                $allCartsForEmail->addFieldToFilter('is_active',array('eq'=>1));
//                $allCartsForEmail->addFieldToFilter('mailchimp_sync_delta',array(
//                    array('neq' => '0000-00-00 00:00:00'),
//                    array('null',false)
//                ));
//                $allCartsForEmail->addFieldToFilter('mailchimp_deleted',array('eq'=>0));
//                $allCartsForEmail->addFieldToFilter('customer_email',array('eq'=>$cart->getCustomerEmail()));
            $allCartsForEmail = $this->_getAllCartsByEmail($cart->getCustomerEmail());
            foreach($allCartsForEmail as $cartForEmail)
            {
                $allCarts[$this->counter]['method'] = 'DELETE';
                $allCarts[$this->counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts/' . $cartForEmail->getEntityId();
                $allCarts[$this->counter]['operation_id'] = $this->batchId . '_' . $cartForEmail->getEntityId();
                $allCarts[$this->counter]['body'] = '';
                $cartForEmail->setData("mailchimp_sync_delta", Varien_Date::now());
                $cartForEmail->setMailchimpDeleted(1);
                $cartForEmail->save();
                $this->counter += 1;
            }
            $allCartsForEmail->clear();
            $allCarts[$this->counter]['method'] = 'DELETE';
            $allCarts[$this->counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts/' . $cart->getEntityId();
            $allCarts[$this->counter]['operation_id'] = $this->batchId . '_' . $cart->getEntityId();
            $allCarts[$this->counter]['body'] = '';
            $cart->setData("mailchimp_sync_delta", Varien_Date::now());
            $cart->setMailchimpDeleted(1);
            $cart->save();
            $this->counter += 1;
        }
        return $allCarts;
    }

    /**
     * @param $mailchimpStoreId
     * @return array
     */
    protected function _getModifiedQuotes($mailchimpStoreId)
    {
        $allCarts = array();
        $modifiedCarts = Mage::getModel('sales/quote')->getCollection();
        // select carts with no orders
        $modifiedCarts->addFieldToFilter('is_active',array('eq'=>1));
        // select carts already sent to mailchimp and moodifief after
        $modifiedCarts->addFieldToFilter('mailchimp_sync_delta',array(
            array('neq' => '0000-00-00 00:00:00'),
            array('null',false)
        ));
        $modifiedCarts->addFieldToFilter('mailchimp_sync_delta',array('lt'=>new Zend_Db_Expr('updated_at')));
        // and not deleted in mailchimp
        $modifiedCarts->addFieldToFilter('mailchimp_deleted',array('eq'=>0));
        $modifiedCarts->getSelect()->limit(self::BATCH_LIMIT);
        foreach($modifiedCarts as $cart)
        {
            $allCarts[$this->counter]['method'] = 'DELETE';
            $allCarts[$this->counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts/' . $cart->getEntityId();
            $allCarts[$this->counter]['operation_id'] = $this->batchId . '_' . $cart->getEntityId();
            $allCarts[$this->counter]['body'] = '';
            $this->counter += 1;
            $customer = Mage::getModel("customer/customer");
            $customer->setWebsiteId(Mage::getModel('core/store')->load($cart->getStoreId())->getWebsiteId());
            $customer->loadByEmail($cart->getCustomerEmail());
            if($customer->getEmail()!=$cart->getCustomerEmail()) {
                $allCartsForEmail = $this->_getAllCartsByEmail($cart->getCustomerEmail());
                foreach ($allCartsForEmail as $cartForEmail) {
                    $allCarts[$this->counter]['method'] = 'DELETE';
                    $allCarts[$this->counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts/' . $cartForEmail->getEntityId();
                    $allCarts[$this->counter]['operation_id'] = $this->batchId . '_' . $cartForEmail->getEntityId();
                    $allCarts[$this->counter]['body'] = '';
                    $cartForEmail->setData("mailchimp_sync_delta", Varien_Date::now());
                    $cartForEmail->setMailchimpDeleted(1);
                    $cartForEmail->save();
                    $this->counter += 1;
                }
                $allCartsForEmail->clear();
            }
            if(!$cart->getCustomerId()&&$customer->getEmail()==$cart->getCustomerEmail())
            {
                continue;
            }
            if($cart->getAllVisibleItems().count()) {
                $cartJson = $this->_makeCart($cart, $mailchimpStoreId);
                $allCarts[$this->counter]['method'] = 'POST';
                $allCarts[$this->counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts';
                $allCarts[$this->counter]['operation_id'] = $this->batchId . '_' . $cart->getEntityId();
                $allCarts[$this->counter]['body'] = $cartJson;
                $cart->setData("mailchimp_sync_delta", Varien_Date::now());
                $cart->save();
                $this->counter += 1;
            }
        }
        return $allCarts;
    }

    /**
     * @param $mailchimpStoreId
     * @return array
     */
    protected function _getNewQuotes($mailchimpStoreId)
    {
        $allCarts = array();
        $newCarts = Mage::getModel('sales/quote')->getCollection();
        $newCarts->addFieldToFilter('is_active',array('eq'=>1))
            ->addFieldToFilter('mailchimp_sync_delta',
                array(
                    array('eq'=>'0000-00-00 00:00:00'),
                    array('null'=>true)
                )
            );
        $newCarts->addFieldToFilter('created_at',array('from'=>$this->firstDate));
        $newCarts->addFieldToFilter('customer_email',array('notnull'=>true));
        $newCarts->getSelect()->limit(self::BATCH_LIMIT);
        foreach($newCarts as $cart)
        {
            $customer = Mage::getModel("customer/customer");
            $customer->setWebsiteId(Mage::getModel('core/store')->load($cart->getStoreId())->getWebsiteId());
            $customer->loadByEmail($cart->getCustomerEmail());
            if($customer->getEmail()!=$cart->getCustomerEmail()) {
                $allCartsForEmail = $this->_getAllCartsByEmail($cart->getCustomerEmail());
                foreach ($allCartsForEmail as $cartForEmail) {
                    $allCarts[$this->counter]['method'] = 'DELETE';
                    $allCarts[$this->counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts/' . $cartForEmail->getEntityId();
                    $allCarts[$this->counter]['operation_id'] = $this->batchId . '_' . $cartForEmail->getEntityId();
                    $allCarts[$this->counter]['body'] = '';
                    $cartForEmail->setData("mailchimp_sync_delta", Varien_Date::now());
                    $cartForEmail->setMailchimpDeleted(1);
                    $cartForEmail->save();
                    $this->counter += 1;
                }
                $allCartsForEmail->clear();
            }
            if(!$cart->getCustomerId()&&$customer->getEmail()==$cart->getCustomerEmail())
            {
                continue;
            }
            if($cart->getAllVisibleItems().count()) {
                $cartJson = $this->_makeCart($cart, $mailchimpStoreId);
                $allCarts[$this->counter]['method'] = 'POST';
                $allCarts[$this->counter]['path'] = '/ecommerce/stores/' . $mailchimpStoreId . '/carts';
                $allCarts[$this->counter]['operation_id'] = $this->batchId . '_' . $cart->getEntityId();
                $allCarts[$this->counter]['body'] = $cartJson;
                $cart->setData("mailchimp_sync_delta", Varien_Date::now());
                $cart->save();
                $this->counter += 1;
            }
        }
        return $allCarts;
    }

    /**
     * @param $email
     */
    protected function _getAllCartsByEmail($email)
    {
        $allCartsForEmail = Mage::getModel('sales/quote')->getCollection();
        $allCartsForEmail->addFieldToFilter('is_active',array('eq'=>1));
        $allCartsForEmail->addFieldToFilter('mailchimp_sync_delta',array(
            array('neq' => '0000-00-00 00:00:00'),
            array('null',false)
        ));
        $allCartsForEmail->addFieldToFilter('mailchimp_deleted',array('eq'=>0));
        $allCartsForEmail->addFieldToFilter('customer_email',array('eq'=>$email));
        return $allCartsForEmail;
    }

    /**
     * @param $cart
     * @param $mailchimpStoreId
     * @return string
     */
    protected function _makeCart($cart,$mailchimpStoreId)
    {
        $oneCart = array();
        $oneCart['id'] = $cart->getEntityId();
        $oneCart['customer'] = $this->_getCustomer($cart,$mailchimpStoreId);
//        $oneCart['campaign_id'] = '';
        $oneCart['checkout_url'] = $this->_getCheckoutUrl($cart);
        $oneCart['currency_code'] = $cart->getQuoteCurrencyCode();
        $oneCart['order_total'] = $cart->getGrandTotal();
        $oneCart['tax_total'] = 0;
        $lines = array();
        // get all items on the cart
        $items = $cart->getAllVisibleItems();
        $item_count = 0;
        foreach($items as $item)
        {
            $line = array();
            if($item->getProductType()==Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
                $options = $item->getProductOptions();
                $sku = $options['simple_sku'];
                $variant = Mage::getModel('catalog/product')->getIdBySku($sku);
            }
            else {
                $variant = $item->getProductId();
            }

            $line['id'] = (string)$item_count;
            $line['product_id'] = $item->getProductId();
            $line['product_variant_id'] = $variant;
            $line['quantity'] = (int)$item->getQtyOrdered();
            $line['price'] = $item->getPrice();
            $lines[] = $line;
            $item_count += 1;
        }
        $oneCart['lines'] = $lines;

        $jsonData = "";

        //enconde to JSON
        try {

            $jsonData = json_encode($oneCart);

        } catch (Exception $e) {
            //json encode failed
            Mage::helper('mailchimp')->logError("Carts ".$cart->getId()." json encode failed");
        }

        return $jsonData;
    }
    // @todo calculate the checkout url for the cart
    protected function _getCheckoutUrl($cart)
    {
        $token = md5(rand(0, 9999999));
        $url = Mage::getModel('core/url')->setStore($cart->getStoreId())->getUrl('', array('_nosid' => true)) . 'mailchimp/cart/loadquote?id=' . $cart->getEntityId() . '&token=' . $token;
        $cart->setMailchimpToken($token);
        return $url;
    }
    protected function _getCustomer($cart,$mailchimpStoreId)
    {
        $api = $this->_getApi();
        $customers = $api->ecommerce->customers->getByEmail($mailchimpStoreId, $cart->getCustomerEmail());
        if($customers['total_items']>0)
        {
            $customer = array(
              'id' => $customers['customers'][0]['id']
            );
        }
        else {
            if (!$cart->getCustomerId()) {
                $customer = array(
                    "id" => "GUEST-" . date('Y-m-d-H-i-s'),
                    "email_address" => $cart->getCustomerEmail(),
                    "opt_in_status" => false
                );
            } else {
                $customer = array(
                    "id" => $cart->getCustomerId(),
                    "email_address" => $cart->getCustomerEmail(),
                    "opt_in_status" => Ebizmarts_MailChimp_Model_Api_Customers::DEFAULT_OPT_IN
                );
            }
            $firstName = $cart->getCustomerFirstname();
            if($firstName) {
                $customer["first_name"] = $firstName;
            }
            $lastName = $cart->getCustomerLastname();
            if($lastName) {
                $customer["last_name"] = $lastName;
            }
            $billingAddress = $cart->getBillingAddress();
            if ($billingAddress) {
                $street = $billingAddress->getStreet();
                $customer["address"] = array(
                    "address1" => $street[0],
                    "address2" => count($street) > 1 ? $street[1] : "",
                    "city" => $billingAddress->getCity(),
                    "province" => $billingAddress->getRegion() ? $billingAddress->getRegion() : "",
                    "province_code" => $billingAddress->getRegionCode() ? $billingAddress->getRegionCode() : "",
                    "postal_code" => $billingAddress->getPostcode(),
                    "country" => Mage::getModel('directory/country')->loadByCode($billingAddress->getCountry())->getName(),
                    "country_code" => $billingAddress->getCountry()
                );
            }
            //company
            if ($billingAddress->getCompany()) {
                $customer["company"] = $billingAddress->getCompany();
            }
        }
        return $customer;
    }
    protected function _getApi()
    {
        if(!$this->api)
        {
            $apiKey = Mage::helper('mailchimp')->getConfigValue(Ebizmarts_MailChimp_Model_Config::GENERAL_APIKEY);
            $this->api = new Ebizmarts_Mailchimp($apiKey,null,'Mailchimp4Magento'.(string)Mage::getConfig()->getNode('modules/Ebizmarts_MailChimp/version'));
        }
        return $this->api;
    }
}