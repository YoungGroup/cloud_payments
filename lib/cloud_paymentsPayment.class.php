<?php

/**
 * @name CloudPayments
 * @description CloudPayments payment plugin
 * @author Andrey Kornienko <ant@kaula.ru>
 *
 * Plugin settings parameters must be specified in file lib/config/settings.php
 * @property string $publicId
 * @property string $apiSecret
 * @property string $taxationSystem
 * @property string $vat
 */
class cloud_paymentsPayment extends waPayment implements waIPayment
{
  // Taxation system types
  const TS_GENERAL = 0;
  const TS_SIMPLIFIED_INCOME_ONLY = 1;
  const TS_SIMPLIFIED_INCOME_MINUS_EXPENSE = 2;
  const TS_IMPUTED_INCOME = 3;
  const TS_AGRICULTURE = 4;
  const TS_LICENSE = 5;

  private $order_id;
  private $pattern = '/^(\w[\w\d]+)_([\w\d]+)_(.+)$/';
  private $template = '%s_%s_%s';

  /**
   * Returns array of ISO3 codes of enabled currencies (from settings)
   * supported by payment gateway.
   *
   * Note: Russian legal entities should contact CloudPayments if they want
   * to receive payments in currencies other then RUB.
   *
   * @see https://cloudpayments.ru/Docs/Directory#currencies
   *
   * @return string[]
   */
  public function allowedCurrency()
  {
    return array(
      'RUB',
      'EUR',
      'USD',
      'GBP',
      'UAH',
      'BYN',
      'KZT',
      'AZN',
      'CHF',
      'CZK',
      'CAD',
      'PLN',
      'SEK',
      'TRY',
      'CNY',
      'INR',
      'BRL',
    );
  }

  /**
   * Generates payment form HTML code.
   *
   * Payment form can be displayed during checkout or on order-viewing page.
   * Form "action" URL can be that of the payment gateway or of the current
   * page (empty URL). In the latter case, submitted data are passed again to
   * this method for processing, if needed; e.g., verification, saving,
   * forwarding to payment gateway, etc.
   *
   * @param array $payment_form_data Array of POST request data received from
   *   payment form
   * (if no "action" URL is specified for the form)
   * @param waOrder $order_data Object containing all available order-related
   *   information
   * @param bool $auto_submit Whether payment form data must be automatically
   *   submitted (useful during checkout)
   * @return string Payment form HTML
   * @throws waException
   */
  public function payment($payment_form_data, $order_data, $auto_submit = false)
  {
    return $this->createPayment($payment_form_data, $order_data, $auto_submit);
  }

  /**
   * Create payment in Cloud Payments and redirect user to payment page
   *
   * @param $payment_form_data
   * @param $order_data
   * @param bool $auto_submit
   * @return null
   */
  private function createPayment($payment_form_data, $order_data, $auto_submit = false)
  {
    $order = waOrder::factory($order_data);

    if (empty($order_data['description_en'])) {
      $order['description_en'] = 'Order '.$order['order_id'];
    }

    $c = new waContact($order_data['customer_contact_id']);

    if (!($email = $c->get('email', 'default'))) {
      $email = $this->getDefaultEmail();
    }

    $args = array(
        'Amount'      => round($order['amount'] * 100),
        'Currency'    => $order->currency,
        'PublicId'    => $this->publicId,
        'Token'       => $this->apiSecret,
        'InvoiceId'   => sprintf(
            $this->template,
            $this->app_id,
            $this->merchant_id,
            $order->id
        ),
        'Description' => mb_substr($order->description, 0, 255, "UTF-8"),
        'Email'       => $email,
    );

    $payment_url = 'https://api.cloudpayments.ru/payments/tokens/auth'; // two-steps

    $this->sendRequest($payment_url, $args);

    // todo get payment url
    if (!$this->payment_url) {
      return null;
    }

    $view = wa()->getView();

    $view->assign('plugin', $this);
    $view->assign('form_url', $this->payment_url);
    $view->assign('auto_submit', $auto_submit);

    return $view->fetch($this->path.'/templates/payment.html');
  }

  /**
   * Main method. Call API with params
   *
   * @param string $api_url API Url
   * @param array $args API params
   *
   * @return mixed
   * @throws HttpException
   * @throws waException
   */
  private function sendRequest($api_url, $args)
  {
    $this->error = '';
    //todo add string $args support
    //$proxy = 'http://192.168.5.22:8080';
    //$proxyAuth = '';
    if (is_array($args)) {
      $args = json_encode($args);
    }
    //Debug::trace($args);
    if ($curl = curl_init()) {
      curl_setopt($curl, CURLOPT_URL, $api_url);
      curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($curl, CURLOPT_POST, true);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $args);
      curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
      $out = curl_exec($curl);

      $info = curl_getinfo($curl);

      $this->response = $out;
      $json = json_decode($out);

      if ($json) {
        if (@$json->ErrorCode !== '0') {
          $this->error = @$json->Details;
        } else {
          $this->payment_url = @$json->PaymentURL;
          $this->payment_id = @$json->PaymentId;
          $this->status = @$json->Status;
        }
      }
      curl_close($curl);

      if ($this->testmode || $this->send_log) {
        waLog::log('Sent to: '.$api_url."\n".$args, 'payment/tinkoffSend.log');
        waLog::log('Received: http_code: '.ifset($info['http_code']).'; response: '.$out, 'payment/tinkoffSend.log');
      }
      return $json ? $json : $out;

    } else {
      throw new waException('Cannot create connection to '.$api_url.' with args '.$args);
    }
  }

  /**
   * Plugin initialization for processing callbacks received from payment
   * gateway.
   *
   * @param array $request Request data array ($_REQUEST)
   * @return waPayment
   * @throws waPaymentException
   */
  protected function callbackInit($request)
  {
    if (preg_match($this->pattern, ifset($request['InvoiceId']), $matches)) {
      $this->app_id = $matches[1];
      $this->merchant_id = $matches[2];
      $this->order_id = $matches[3];
    } else {
      throw new waPaymentException('Invalid invoice number');
    }

    return parent::callbackInit($request);
  }

  /**
   * Actual processing of callbacks from payment gateway.
   *
   * Plugin settings are already initialized and available (see
   * callbackInit()).
   * Request parameters are checked and app's callback handler is called, if
   * necessary:
   *
   * $this->execAppCallback($state, $transaction_data)
   *
   * $state should be one of the following constants defined in the waPayment:
   * CALLBACK_PAYMENT, CALLBACK_REFUND, CALLBACK_CONFIRMATION,
   * CALLBACK_CAPTURE, CALLBACK_DECLINE, CALLBACK_CANCEL, CALLBACK_CHARGEBACK
   *
   * @see https://developers.webasyst.ru/plugins/payment-plugins/
   *
   * @throws waPaymentException
   * @param array $request Request data array ($_REQUEST) received from gateway
   * @return array Associative array of optional callback processing result
   *   parameters:
   *     'redirect' => URL to redirect user upon callback processing
   *     'template' => path to template to be used for generation of HTML page
   *   displaying callback processing results; false if direct output is used
   *   if not specified, default template displaying message 'OK' is used
   *     'header'   => associative array of HTTP headers ('header name' =>
   *   'header value') to be sent to user's browser upon callback processing,
   *   useful for cases when charset and/or content type are different from
   *   UTF-8 and text/html
   *
   *     If a template is used, returned result is accessible in template
   *   source code via $result variable, and method's parameters via $params
   *   variable
   */
  protected function callbackHandler($request)
  {
    $request_fields = array(
      'TransactionId' => 0,
      'InvoiceId'     => '',
      'Description'   => '',
      'Amount'        => 0.0,
      'Currency'      => '', // RUB,...
      'Name'          => '',
      'Email'         => '',
      'Data'          => '',
      'CardFirstSix'  => '',
      'CardLastFour'  => '',
    );
    $request = array_merge($request_fields, $request);

    // Check signature to avoid any fraud and mistakes
    if (!$this->checkSignature()) {
      throw new waPaymentException(
        'Invalid request signature (possible fraud)'
      );
    }

    // Convert request data into acceptable format and save transaction
    $transaction_data = $this->formalizeData($request);
    $transaction_data = $this->saveTransaction($transaction_data, $request);
    $result = $this->execAppCallback(self::CALLBACK_PAYMENT, $transaction_data);
    if (!empty($result['error'])) {
      throw new waPaymentException(
        'Forbidden (validate error): '.$result['error']
      );
    }

    // Send correct response to the CloudPayments server
    wa()
      ->getResponse()
      ->addHeader('Content-Type', 'application/json', true);
    echo json_encode(array('code' => 0));

    // This plugin generates response without using a template
    return array('template' => false);
  }

  /**
   *  Checks HMAC-encoded signature.
   *
   * @return bool
   * @throws \waPaymentException
   */
  private function checkSignature()
  {
    // Check if API secret is configured properly
    if (empty($this->apiSecret)) {
      throw new waPaymentException('API secret is not configured');
    }

    // Get received Content-Hmac value and compare with calculated
    $headers = $this->getAllHeaders();
    $hmac = ifset($headers['Content-Hmac']);
    $signature = base64_encode(
      hash_hmac(
        'sha256',
        file_get_contents('php://input'),
        $this->apiSecret,
        true
      )
    );

    return $hmac == $signature;
  }

  /**
   * Returns all HTTP headers.
   *
   * @see http://php.net/manual/ru/function.getallheaders.php#84262
   *
   * @return array
   */
  private function getAllHeaders()
  {
    $headers = array();
    foreach ($_SERVER as $name => $value) {
      if (substr($name, 0, 5) == 'HTTP_') {
        $headers[str_replace(
          ' ',
          '-',
          ucwords(strtolower(str_replace('_', ' ', substr($name, 5))))
        )] = $value;
      }
    }

    return $headers;
  }

  /**
   * Converts transaction raw data to formatted data.
   *
   * @param array $transaction_raw_data
   * @return array
   */
  protected function formalizeData($transaction_raw_data)
  {
    $transaction_data = parent::formalizeData($transaction_raw_data);

    // Payment details to be showed in the UI of the order
    $fields = array(
      'Name'  => 'Имя держателя карты',
      'Email' => 'E-mail адрес плательщика',
    );
    $view_data = array();
    foreach ($fields as $field => $description) {
      if (ifset($transaction_raw_data[$field])) {
        $view_data[] = $description.': '.$transaction_raw_data[$field];
      }
    }
    $view_data[] = sprintf(
      'Номер карты: %s****%s',
      $transaction_raw_data['CardFirstSix'],
      $transaction_raw_data['CardLastFour']
    );

    $transaction_data = array_merge(
      $transaction_data,
      array(
        'type'        => self::OPERATION_AUTH_ONLY,
        // transaction id assigned by payment gateway
        'native_id'   => ifset($transaction_raw_data['TransactionId']),
        'amount'      => ifset($transaction_raw_data['Amount']),
        'currency_id' => ifset($transaction_raw_data['Currency']),
        'result'      => 1,
        'order_id'    => $this->order_id,
        'view_data'   => implode("\n", $view_data),
      )
    );

    return $transaction_data;
  }
}
