<?php

require_once Mage::getBaseDir('lib').DS.'Affirm'.DS.'Affirm.php';

class Affirm_Affirm_Model_Payment extends Mage_Payment_Model_Method_Abstract
{
    const API_CHARGES_PATH = '/api/v2/charges/';

    /**
     * Form block type
     */
    protected $_formBlockType = 'affirm/payment_form';

    /**
     * Info block type
     */
    protected $_infoBlockType = 'affirm/payment_info';


    const CHECKOUT_TOKEN = 'checkout_token';
    const METHOD_CODE = 'affirm';
    protected $_code  = self::METHOD_CODE;

    /**
     * Availability options
     */
    protected $_isGateway               = true;
    protected $_isInitializeNeeded      = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = true;
    protected $_canRefundInvoicePartial = false;
    protected $_canVoid                 = true;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = false;
    protected $_canSaveCc               = false;
    protected $_canFetchTransactionInfo = true;

    protected $_allowCurrencyCode = array('USD');

    // TODO(brian): replace with a dynamically loaded list of states
    // TODO(brian): use region ids instead of state names, but be careful to associate
    // states and ids correctly!
    private static $regions_where_affirm_cannot_loan = array(
      'Alabama',
      'Delaware',
      'Idaho',
      'Maryland',
      'Mississippi',
      'Missouri',
      'Nevada',
      'New Mexico',
      'North Dakota',
      'Rhode Island',
      'South Dakota'
    );

    /* Determines whether this payment method is available for the purchase
     * given by |quote|.
     */
    public function isAvailable($quote = null)
    {
      if (!parent::isAvailable($quote) || empty($quote)) {
        return false;
      }

      if (static::isInDisallowedRegions($quote->getBillingAddress()->getRegion())) {
        return false;
      }

      // additional validations may be added here

      return true;
    }

    private static function isInDisallowedRegions($region) {
      return in_array($region, self::$regions_where_affirm_cannot_loan);
    }

    /**
     * Check method for processing with base currency
     *
     * @param string $currencyCode
     * @return boolean
     */
    public function canUseForCurrency($currencyCode)
    {
        if (!in_array($currencyCode, $this->getAcceptedCurrencyCodes())) {
            return false;
        }
        return true;
    }

    /**
     * Return array of currency codes supplied by Payment Gateway
     *
     * @return array
     */
    public function getAcceptedCurrencyCodes()
    {
        if (!$this->hasData('_accepted_currency')) {
            $acceptedCurrencyCodes = $this->_allowCurrencyCode;
            $acceptedCurrencyCodes[] = $this->getConfigData('currency');
            $this->setData('_accepted_currency', $acceptedCurrencyCodes);
        }
        return $this->_getData('_accepted_currency');
    }

    public function getChargeId()
    {
        return $this->getInfoInstance()->getAdditionalInformation("charge_id");
    }

    protected function setChargeId($charge_id)
    {
        return $this->getInfoInstance()->setAdditionalInformation("charge_id", $charge_id);
    }

    public function getBaseApiUrl()
    {
        return $this->getConfigData('api_url');
    }

    // TODO(brian): extract to a separate class and use DI to make it testable/mockable
    public function _api_request($method, $path, $data=null)
    {
        $url = trim($this->getBaseApiUrl(), "/") . self::API_CHARGES_PATH . $path;

        $client = new Zend_Http_Client($url);

        if ($method == Zend_Http_Client::POST && $data)
        {
            $json = json_encode($data);
            $client->setRawData($json, 'application/json');
        }
        
        $client->setAuth($this->getConfigData('api_key'), $this->getConfigData('secret_key'), Zend_Http_Client::AUTH_BASIC);

        $raw_result = $client->request($method)->getRawBody();
        try{
            $ret_json = Zend_Json::decode($raw_result, Zend_Json::TYPE_ARRAY);
        } catch(Zend_Json_Exception $e)
        {
            Mage::log("Undecodable result:" . $raw_result);
            Mage::throwException(Mage::helper('affirm')->__('Invalid affirm response: '. $raw_result));
        }

        //validate to make sure there are no errors here
        if (isset($ret_json["status_code"]))
        {
            Mage::throwException(Mage::helper('affirm')->__('Affirm error code:'. $ret_json["status_code"] . ' error: '. @$ret_json["message"]));
        }
        Mage::log("Making a request to:". $url. " with data:");
        Mage::log($data);
        Mage::log("Response:");
        Mage::log($ret_json);
        return $ret_json;
    }

    protected function _set_charge_result($result)
    {
        if (isset($result["id"]))
        {
            $this->setChargeId($result["id"]);
        }
        else
        {
            Mage::throwException(Mage::helper('affirm')->__('Affirm charge id not returned from call.'));
        }
    }

    protected function _validate_amount_result($amount, $result)
    {
        if ($result["amount"] != $amount)
        {
            Mage::throwException(Mage::helper('affirm')->__('Affirm authorized amount of ' . $result["amount"].' does not match requested amount of: ' . $amount));
        }
    }

    /**
     * Send capture request to gateway
     *
     * @param Mage_Payment_Model_Info $payment
     * @param decimal $amount
     * @return Mage_Paygate_Model_Authorizenet
     */
    public function capture(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper('affirm')->__('Invalid amount for capture.'));
        }
        $charge_id = $this->getChargeId();
        $amount_cents = $amount * 100;
        Mage::log("capturing amount: $amount");
        if (!$charge_id) {
            Mage::throwException(Mage::helper('affirm')->__('Charge id have not been set.'));
        }
        $result = $this->_api_request(Varien_Http_Client::POST, "{$charge_id}/capture");
        $this->_validate_amount_result($amount_cents, $result);
        return $this;
    }

    /**
     * Refund capture
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     * @return Mage_Paypal_Model_Direct
     */
    public function refund(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper('affirm')->__('Invalid amount for refund.'));
        }
        $charge_id = $this->getChargeId();
        $amount_cents = $amount * 100;
        Mage::log("refunding amount: $amount");
        if (!$charge_id) {
            Mage::throwException(Mage::helper('affirm')->__('Charge id have not been set.'));
        }
        $result = $this->_api_request(Varien_Http_Client::POST, "{$charge_id}/refund", array(
									"amount"=>$amount_cents)
        );
        $this->_validate_amount_result($amount_cents, $result);

        return $this;
    }

    public function void(Varien_Object $payment)
    {
        if (!$this->canVoid($payment)) {
            Mage::throwException(Mage::helper('payment')->__('Void action is not available.'));
        }
        $charge_id = $this->getChargeId();
        if (!$charge_id) {
            Mage::throwException(Mage::helper('affirm')->__('Charge id have not been set.'));
        }
        $result = $this->_api_request(Varien_Http_Client::POST, "{$charge_id}/void");
        return $this;
    }

    /**
     * Send authorize request to gateway
     *
     * @param  Mage_Payment_Model_Info $payment
     * @param  decimal $amount
     * @return Mage_Paygate_Model_Authorizenet
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        if ($amount <= 0) {
            Mage::throwException(Mage::helper('affirm')->__('Invalid amount for authorization.'));
        }

        $amount_cents = $amount * 100;
        $token = $payment->getAdditionalInformation(self::CHECKOUT_TOKEN);
        Mage::log("authorizing amount: $amount");

        $result = $this->_api_request(Varien_Http_Client::POST, "", array(
									self::CHECKOUT_TOKEN=>$token)
					);

        $this->_set_charge_result($result);
        $this->_validate_amount_result($amount_cents, $result);
        $payment->setTransactionId($this->getChargeId())->setIsTransactionClosed(0);
        return $this;
    }

    /**
     * Instantiate state and set it to state object
     * @param string $paymentAction
     * @param Varien_Object
     */
    public function initialize($paymentAction, $stateObject)
    {
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        $stateObject->setState($state);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }


    public function processConfirmOrder($order, $checkout_token)
    {
        $payment = $order->getPayment();

        $payment->setAdditionalInformation(self::CHECKOUT_TOKEN, $checkout_token);
        $action = $this->getConfigData('payment_action');

        //authorize the total amount.
        Affirm_Affirm_Model_Payment::authorizePaymentForOrder($payment, $order);
        $payment->setAmountAuthorized($order->getTotalDue());
        $order->save();
        //can capture as well..
        if ($action == self::ACTION_AUTHORIZE_CAPTURE)
        {
            $payment->setAmountAuthorized($order->getTotalDue());
            $payment->setBaseAmountAuthorized($order->getBaseTotalDue());
            $payment->capture(null);
            $order->save();
        }
    }

    /**
     * Return Order place redirect url
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
          return Mage::getUrl('affirm/payment/redirect', array('_secure' => true));
    }

    public function formatCents($currency, $amount)
    {
        return Affirm_Util::formatCents($amount);
    }

    public function getCheckoutObject($order)
    {
        $info = $this->getInfoInstance(); // TODO(brian): remove unused variable
        $shipping_address = $order->getShippingAddress();
        $shipping = null;
        if ($shipping_address)
        {
            $shipping = array(
                "name"=> array("full"=>$shipping_address->getName()),
                "address"=> array(
                        "line1" => $shipping_address->getStreet(1),
                        "line2" => $shipping_address->getStreet(2),
                        "city" => $shipping_address->getCity(),
                        "state" => $shipping_address->getRegion(),
                        "country" => $shipping_address->getCountryModel()->getIso2Code(),
                        "zipcode" => $shipping_address->getPostcode(),
                      ));
        }

        $billing_address = $order->getBillingAddress();
        $billing = array(
                "email"=>$order->getCustomerEmail(),
                "name"=> array("full"=>$billing_address->getName()),
                "address"=> array(
                        "line1" => $billing_address->getStreet(1),
                        "line2" => $billing_address->getStreet(2),
                        "city" => $billing_address->getCity(),
                        "state" => $billing_address->getRegion(),
                        "country" => $billing_address->getCountryModel()->getIso2Code(),
                        "zipcode" => $billing_address->getPostcode(),
                      ));

        $items = array();
        $currency = $order->getOrderCurrency();
        $products = Mage::getModel('catalog/product');
        foreach($order->getAllVisibleItems() as $order_item)
        {
            $options = $order_item->getProductOptions();
            $productId = $options["info_buyRequest"]["product"];
            $product = $products->load($productId);

            $items[] = array(
                "sku" => $order_item->getSku(),
                "display_name" => $order_item->getName(),
                "item_url" => $product->getProductUrl(),
                "item_image_url" => $product->getImageUrl(),
                "qty" => intval($order_item->getQtyOrdered()),
                "unit_price" => $this->formatCents($currency, $order_item->getPrice())
            );
        }

        // TODO(brian): test checkout/onepage urls. it's unclear whether this
        // is enabled for all merchants or whether merchant customization could
        // cause this to be an invalid destination
        $checkout = array(
            'checkout_id'=>$order->getIncrementId(),
            'currency'=>$order->getOrderCurrencyCode(),
            'shipping_amount'=>$this->formatCents($currency, $order->getShippingAmount()),
            'shipping_type'=>$order->getShippingMethod(),
            'tax_amount'=>$this->formatCents($currency, $order->getTaxAmount()),
            "merchant" => array(
                    "public_api_key"=>$this->getConfigData('api_key'), 
                    "user_confirmation_url"=>Mage::getUrl("affirm/payment/confirm"),
                    "user_cancel_url"=>Mage::helper('checkout/url')->getCheckoutUrl(),
                    "charge_declined_url"=>Mage::helper('checkout/url')->getCheckoutUrl()
                  ),
            "config" => array("required_billing_fields"=> "name,address,email"),
            "items" => $items,
            "billing" => $billing);

        if ($order->getDiscountAmount() > 0.001)
        {
            $checkout["discounts"] = array(array("code"=> $order->getCouponCode(),"amount"=>$this->formatCents($currency, $order->getDiscountAmount())));
        }

        if ($shipping)
        {
            $checkout["shipping"] = $shipping;
        }
        $checkout['financial_product_key'] = $this->getConfigData('financial_product_key');
        return $checkout;
    }

    /* A hacky thing used to access a private method (authorize(...)) on the
     * payment object in order to provide compatibility with version 1.4.0.1 CE.
     *
     * FIXME(brian): take a closer look at the payment class at version 1.4.
     * Surely, there _must_ be a way to accomplish this without reflection.
     *
     * TODO(brian): Write a regression test to catch incompatibilities with
     * other Magento versions.
     */
    private static function authorizePaymentForOrder($payment, $order)
    {
      $moduleVersion = Mage::getConfig()->getModuleConfig("Mage_Sales")->version;
      $incompatibleVersions = array(
        "0.9.56"
      );
      if (in_array($moduleVersion, $incompatibleVersions)) {
        Affirm_Affirm_Model_Payment::callPrivateMethod($payment, "_authorize", true, $order->getBaseTotalDue());
      } else {
        $payment->authorize(true, $order->getBaseTotalDue());
      }
    }

    // TODO(brian): move this function to a helper library
    private static function callPrivateMethod($object, $methodName)
    {
      $reflectionClass = new \ReflectionClass($object);
      $reflectionMethod = $reflectionClass->getMethod($methodName);
      $reflectionMethod->setAccessible(true);

      $params = array_slice(func_get_args(), 2); //get all the parameters after $methodName
      return $reflectionMethod->invokeArgs($object, $params);
    }
}
