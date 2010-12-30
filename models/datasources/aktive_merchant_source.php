<?php
/**
 * Wrapper datasource for the Aktive Merchant vendor library found here: https://github.com/akDeveloper/Aktive-Merchant
 *
 * @package Cart
 * @author Dean
 */
class AktiveMerchantSource extends DataSource {
	/**
	 * Description string for this Data Source.
	 *
	 * @var string
	 * @access public
	 */
	var $description = 'Aktive Merchant Datasource';
	
	/**
	 * Holds the database settings for the datasource
	 *
	 * @var array
	 */
	var $config = array();
	
	/**
	 * Stores the 'return_url' and 'cancel_return_url'
	 *
	 * @var array
	 */
	var $urls = array(
		'return_url' => '',
		'cancel_return_url' => '',
	);
	
	/**
	 * Gateway Object
	 *
	 * @var string
	 */
	var $gateway;
	
	/**
	 * Holds the error message if there was one
	 *
	 * @var string
	 */
	var $error = '';
  
	/**
	 * constructer.  Load the HttpSocket into the Http var.
	 */
	function __construct($config){
		parent::__construct($config);
		App::Import('Vendor', 'Cart.AktiveMerchant', array('file' => 'lib'.DS.'merchant.php'));
		$this->_load();
	}
	
	/**
	 * Loads a gateway object with the passed options
	 *
	 * @param string $options 
	 * @return void
	 * @author Dean
	 */
	function _load($options = array()) {
		if (!$options) {
			$options = array('login', 'password', 'signature');
		}
		if (isset($this->config['testing']) && $this->config['testing']) {
			Merchant_Billing_Base::mode('test');
		}
		$initOptions = array();
		foreach ($options as $option) {
			if (isset($this->config[$option])) {
				$initOptions[$option] = $this->config[$option];
			}
		}
		$gatewayClass = 'Merchant_Billing_' . $this->config['gateway'];
		$this->gateway = new $gatewayClass($initOptions);
	}
	
	/**
	 * Creates and returns a credit card object if it is valid
	 *
	 * $data = array(
	 *		'first_name' => 'John',
	 *		'last_name' => 'Doe',
	 *		'number' => '5105105105105100',
	 *		'month' => '12',
	 *		'year' => '2012',
	 *		'verification_value' => '123',
	 *		'type' => 'master',
	 *	);
	 *
	 * @param string $data 
	 * @return $creditCard Merchant_Billing_CreditCard or false if the card is invalid
	 */
	public function creditCard($data) {
		if ($data instanceof Merchant_Billing_CreditCard) {
			if ($data->is_valid()) {
				return $data;
			} else {
				return false;
			}
		} else {
			if (isset($data['credit_card'])) {
				$data = $data['credit_card'];
			}
			$creditCard = new Merchant_Billing_CreditCard($data);
			if ($creditCard->is_valid()) {
				return $creditCard;
			} else {
				return false;
			}
		}
	}
	
	/**
	 * Submits a purchase to the active payment gateway
	 *
	 * @param decimal $amount 
	 * @param array $data requires 'description' and 'address' keys to be properly filled
	 * @return mixed $success Returns the REF ID# if successful, else false
	 */
	public function purchase($amount, $data) {
		try {
			// Paypal Express works in 2 separate parts
			if ($this->config['gateway'] == 'PaypalExpress') {
				if (!isset($_GET['token'])) {
					$this->_startPurchase($amount, $data);
				} else {
					$response = $this->_completePurchase();
				}
			} else {
				$creditCard = $this->creditCard($data);
				if (!$creditCard) {
					$this->error = 'Invalid Credit Card';
					return false;
				}
				
				$options = array(
					'order_id' => 'REF' . $this->gateway->generate_unique_id(),
					'description' => $data['description'],
					'address' => $data['address'],
				);
				
				$response = $this->gateway->purchase($amount, $creditCard, $options);
			}
			
			if ($response->success()) {
				if (isset($options['order_id'])) {
					return $options['order_id'];
				} else {
					// TODO Should always return order_id, paypal express currently doesn't save it due to the 2 step process
					return true;
				}
			} else {
				$this->error = $response->message();
					$this->log('Cart.AktiveMerchantSource: ' . $response->message());
				return false;
			}
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			$this->log('Cart.AktiveMerchantSource: ' . $e->getMessage());
			return false;
		}
	}
	
	/**
	 * PaypalExpress: Creates the transaction and sends the user to paypal to login
	 *
	 * @param string $data 
	 * @return void
	 * @author Dean
	 */
	protected function _startPurchase($amount, $data) {
		$this->creditCard($data);
		
		$pageURL = (@$_SERVER["HTTPS"] == "on") ? "https://" : "http://";
		if ($_SERVER["SERVER_PORT"] != "80") {
			$pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
		} else {
			$pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
		}
		
		$options = array(
			'order_id' => 'REF' . $this->gateway->generate_unique_id(),
			'description' => $data['description'],
			'address' => $data['address'],
			'return_url' => $pageURL,
		);
		
		$response = $this->gateway->setup_purchase($amount, array_merge($this->urls, $options));
		die(header('Location: ' . $this->gateway->url_for_token($response->token())));
	}
	
	/**
	 * PaypalExpress: When the user returns from paypal after logging in, the transaction is finalized
	 *
	 * @return void
	 * @author Dean
	 */
	protected function _completePurchase() {
		$response = $this->gateway->get_details_for($_GET['token'], $_GET['PayerID']);
		return $this->gateway->purchase($response->amount());
	}
	
}
?>