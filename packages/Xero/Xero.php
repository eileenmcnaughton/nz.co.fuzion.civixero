<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

/**
 * From the README file:
 *
 * Project Name: PHP Xero
 * Class Name: Xero
 * Author: Ronan Quirke, Xero (dependent on the work of others, mainly David
 * Pitman - see below) Date: May 2012
 *
 * Description:
 * A class for interacting with the xero (xero.com) private application API.
 * It could also be used for the public application API too, but it hasn't been
 * tested with that.  More documentation for Xero can be found at
 * http://blog.xero.com/developer/api-overview/  It is suggested you become
 * familiar with the API before using this class, otherwise it may not make
 * much sense to you - http://blog.xero.com/developer/api/
 *
 * Thanks for the Oauth* classes provided by Andy Smith, find more about them
 * at http://oauth.googlecode.com/.  The OAuthSignatureMethod_Xero class was
 * written by me, as required by the Oauth classes.  The ArrayToXML classes
 * were sourced from wwwzealdcom's work as shown on the comment dated August
 * 30, 2009 on this page:
 * http://snipplr.com/view/3491/convert-php-array-to-xml-or-simple-xml-object-if-you-wish/
 *  I made a few minor changes to that code to overcome some bugs.
 *
 * ---
 *
 * License (applies to Xero and Oauth* classes):
 * The MIT License
 *
 * Copyright (c) 2007 Andy Smith (Oauth*)
 * Copyright (c) 2010 David Pitman (Xero)
 * Copyright (c) 2012 Ronan Quirke, Xero (Xero)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

class Xero {

  const ENDPOINT = 'https://api.xero.com/api.xro/2.0/';

  private $key;

  private $secret;

  private $public_cert;

  private $guzzleClient;

  private $consumer;

  private $token;

  private $signature_method;

  private $format;

  /**
   * @var string
   */
  private $accessToken;

  /**
   *
   * @var string
   */
  private $tenantID;

  public function __construct($accessToken, $tenantID, $format = 'json') {
    if (!$accessToken || !$tenantID) {
      $msg = 'Missing ' . !$accessToken ? 'Access Token.' : ' Tenant ID.';
      throw new Exception($msg);
    }
    $this->accessToken = $accessToken;
    $this->tenantID = $tenantID;
    $this->format = (in_array($format, ['xml', 'json'])) ? $format : 'json';
  }

  public function __call($name, $arguments) {
    // OAuth 2.0 Headers.
    $headers = [
      'Authorization: Bearer ' . $this->accessToken,
      'Xero-tenant-id: ' . $this->tenantID,
    ];

    $name = strtolower($name);
    $valid_methods = [
      'accounts',
      'banktransactions',
      'brandingthemes',
      'contacts',
      'creditnotes',
      'currencies',
      'employees',
      'expenseclaims',
      'invoices',
      'items',
      'journals',
      'manualjournals',
      'organisation',
      'payments',
      'receipts',
      'taxrates',
      'trackingcategories',
      'users',
    ];
    $valid_post_methods = [
      'banktransactions',
      'contacts',
      'creditnotes',
      'employees',
      'expenseclaims',
      'invoices',
      'items',
      'manualjournals',
      'receipts',
      'banktransactions',
    ];
    $valid_put_methods = [
      'banktransactions',
      'contacts',
      'creditnotes',
      'employees',
      'expenseclaims',
      'invoices',
      'items',
      'manualjournals',
      'payments',
      'receipts',
    ];
    $valid_get_methods = [
      'accounts',
      'banktransactions',
      'brandingthemes',
      'contacts',
      'creditnotes',
      'currencies',
      'employees',
      'expenseclaims',
      'invoices',
      'items',
      'journals',
      'manualjournals',
      'organisation',
      'payments',
      'receipts',
      'taxrates',
      'trackingcategories',
      'users',
    ];
    $methods_map = [
      'accounts' => 'Accounts',
      'banktransactions' => 'BankTransactions',
      'brandingthemes' => 'BrandingThemes',
      'contacts' => 'Contacts',
      'creditnotes' => 'CreditNotes',
      'currencies' => 'Currencies',
      'employees' => 'Employees',
      'expenseclaims' => 'ExpenseClaims',
      'invoices' => 'Invoices',
      'items' => 'Items',
      'journals' => 'Journals',
      'manualjournals' => 'ManualJournals',
      'organisation' => 'Organisation',
      'payments' => 'Payments',
      'receipts' => 'Receipts',
      'taxrates' => 'TaxRates',
      'trackingcategories' => 'TrackingCategories',
      'users' => 'Users',

    ];
    if (!in_array($name, $valid_methods)) {
      throw new XeroException('The selected method does not exist. Please use one of the following methods: ' . implode(', ', $methods_map));
    }
    if ((count($arguments) == 0) || (is_string($arguments[0])) || (is_numeric($arguments[0])) || ($arguments[0] === FALSE)) {
      $where = FALSE;
      //it's a GET request
      if (!in_array($name, $valid_get_methods)) {
        return FALSE;
      }
      $filterid = (count($arguments) > 0) ? strip_tags(strval($arguments[0])) : FALSE;
      if (isset($arguments[1])) {
        $modified_after = (count($arguments) > 1) ? str_replace('X', 'T', date('Y-m-dXH:i:s', strtotime($arguments[1]))) : FALSE;
      }
      if (isset($arguments[2])) {
        $where = (count($arguments) > 2) ? $arguments[2] : FALSE;
      }
      if (is_array($where) && (count($where) > 0)) {
        $temp_where = '';
        foreach ($where as $wf => $wv) {
          if (is_bool($wv)) {
            $wv = ($wv) ? "%3d%3dtrue" : "%3d%3dfalse";
          }
          else {
            if (is_array($wv)) {
              if (is_bool($wv[1])) {
                $wv = ($wv[1]) ? rawurlencode($wv[0]) . "true" : rawurlencode($wv[0]) . "false";
              }
              else {
                $wv = rawurlencode($wv[0]) . "%22{$wv[1]}%22";
              }
            }
            else {
              $wv = "%3d%3d%22$wv%22";
            }
          }
          $temp_where .= "%26%26$wf$wv";
        }
        $where = strip_tags(substr($temp_where, 6));
      }
      else {
        $where = strip_tags(strval($where));
      }
      $order = (count($arguments) > 3) ? strip_tags(strval($arguments[3])) : FALSE;
      $acceptHeader = (!empty($arguments[4])) ? $arguments[4] : '';
      $method = $methods_map[$name];
      $xero_url = self::ENDPOINT . $method;
      if ($filterid) {
        $xero_url .= "/$filterid";
      }
      if (isset($where)) {
        $xero_url .= "?where=$where";
      }
      if ($order) {
        $xero_url .= "&order=$order";
      }
      if ($acceptHeader === 'pdf') {
        $headers[] = "Accept: application/" . $acceptHeader;
      }
      if (isset($modified_after) && $modified_after != FALSE) {
        $headers[] = "If-Modified-Since: $modified_after";
      }
      $temp_xero_response = (string) $this->getGuzzleClient()->get($xero_url, [
        'body' => '',
        'curl' => [
          CURLOPT_RETURNTRANSFER => TRUE,
          // Seems bad, historically set to this.
          CURLOPT_SSL_VERIFYPEER => FALSE,
          CURLOPT_HTTPHEADER => $headers,
        ],
      ])->getBody();
      if ($acceptHeader == 'pdf') {
        return $temp_xero_response;
      }
      try {
        if (@simplexml_load_string($temp_xero_response) == FALSE) {
          throw new XeroException($temp_xero_response);
        }
        else {
          $xero_xml = simplexml_load_string($temp_xero_response);
        }
      }
      catch (XeroException $e) {
        return $e->getMessage() . "<br/>";
      }


      if ($this->format == 'xml' && isset($xero_xml)) {
        return $xero_xml;
      }
      elseif (isset($xero_xml)) {
        return ArrayToXML::toArray($xero_xml);
      }
    }
    elseif ((count($arguments) == 1) || (is_array($arguments[0])) || (is_a($arguments[0], 'SimpleXMLElement'))) {
      //it's a POST or PUT request
      if (!(in_array($name, $valid_post_methods) || in_array($name, $valid_put_methods))) {
        return FALSE;
      }
      $method = $methods_map[$name];
      if (is_a($arguments[0], 'SimpleXMLElement')) {
        $post_body = $arguments[0]->asXML();
      }
      elseif (is_array($arguments[0])) {
        $args = reset($arguments[0]);
        // Xero limits - Request Size Limit 3.5M
        // 60 calls in rolling 60sec window.
        // 5000 calls in 24 hr window
        $post_body = ArrayToXML::toXML($arguments[0], $rootNodeName = $method);
      }
      $post_body = trim(substr($post_body, (stripos($post_body, ">") + 1)));
      if (in_array($name, $valid_post_methods)) {
        $xero_url = self::ENDPOINT . $method;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->build_http_query(['xml' => $post_body]));
        //curl_setopt($ch, CURLOPT_HEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // $xero_response = curl_exec($ch);
        try {
          $xero_response = (string) $this->getGuzzleClient()->post($xero_url, [
            'body' => $this->build_http_query(['xml' => $post_body]),
            'curl' => [
              CURLOPT_RETURNTRANSFER => TRUE,
              // Seems bad, historically set to this.
              CURLOPT_SSL_VERIFYPEER => FALSE,
              CURLOPT_HTTPHEADER => $headers,
            ],
          ])->getBody();
        }
        catch (ClientException $e) {
          if ($e->getCode() === 429) {
            // This is a case of the limit being exceeded.
            throw new XeroThrottleException($e->getMessage(), $e->getCode(), NULL, $e->getResponse()->getHeader('Retry-After')[0] + time());
          }
          $xero_response = $e->getResponse();
          throw new XeroApiException((string) $xero_response->getBody(), $xero_response->getStatusCode(), $e);
        }
      }
      else {
        $xero_url = self::ENDPOINT . $method;
        $xml = $post_body;
        $fh = fopen('php://memory', 'w+');
        fwrite($fh, $xml);
        rewind($fh);
        $ch = curl_init($xero_url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_PUT, TRUE);
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, strlen($xml));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $xero_response = curl_exec($ch);
        if (!$xero_response) {
          throw new XeroException(curl_error($ch));
        }
      }

      if (isset($fh)) {
        fclose($fh);
      }
      try {
        if (@simplexml_load_string($xero_response) == FALSE) {
          throw new XeroException($xero_response);
        }
        else {
          $xero_xml = simplexml_load_string($xero_response);
        }
      }
      catch (XeroThrottleException $e) {
        // Let it bubble up since it has more information.
        throw $e;
      }
      catch (XeroException $e) {
        if ($e->getCode() === 429) {
          throw $e;
        }
        //display custom message
        return $e->getMessage() ?  ($e->getMessage()  . "<br/>") : '';
      }
      curl_close($ch);
      if (!isset($xero_xml)) {
        return FALSE;
      }
      if ($this->format == 'xml' && isset($xero_xml)) {
        return $xero_xml;
      }
      elseif (isset($xero_xml)) {
        return ArrayToXML::toArray($xero_xml);
      }
    }
    else {
      return FALSE;
    }


  }

  public function __get($name) {
    return $this->$name();
  }

  public function verify() {
    if (!isset($this->consumer) || !isset($this->token) || !isset($this->signature_method)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * @return \GuzzleHttp\Client
   */
  public function getGuzzleClient(): Client {
    return $this->guzzleClient ?? new Client();
  }

  protected function build_http_query($params) {
    if (!$params) {
      return '';
    }

    // Urlencode both keys and values
    $keys = $this->urlencode_rfc3986(array_keys($params));
    $values = $this->urlencode_rfc3986(array_values($params));
    $params = array_combine($keys, $values);

    // Parameters are sorted by name, using lexicographical byte value ordering.
    // Ref: Spec: 9.1.1 (1)
    uksort($params, 'strcmp');

    $pairs = [];
    foreach ($params as $parameter => $value) {
      if (is_array($value)) {
        // If two or more parameters share the same name, they are sorted by their value
        // Ref: Spec: 9.1.1 (1)
        natsort($value);
        foreach ($value as $duplicate_value) {
          $pairs[] = $parameter . '=' . $duplicate_value;
        }
      }
      else {
        $pairs[] = $parameter . '=' . $value;
      }
    }
    // For each parameter, the name is separated from the corresponding value by an '=' character (ASCII code 61)
    // Each name-value pair is separated by an '&' character (ASCII code 38)
    return implode('&', $pairs);
  }

  public function urlencode_rfc3986($input) {
    if (is_array($input)) {
      return array_map([$this, 'urlencode_rfc3986'], $input);
    }
    else {
      if (is_scalar($input)) {
        return str_replace(
          '+',
          ' ',
          str_replace('%7E', '~', rawurlencode($input))
        );
      }
      else {
        return '';
      }
    }
  }

}

//END Xero class

class ArrayToXML {

  /**
   * The main function for converting to an XML document.
   * Pass in a multi dimensional array and this recrusively loops through and
   * builds up an XML document.
   *
   * @param array $data
   * @param string $rootNodeName - what you want the root node to be -
   *   defaultsto data.
   * @param SimpleXMLElement $xml - should only be used recursively
   *
   * @return string XML
   */
  public static function toXML($data, $rootNodeName = 'ResultSet', &$xml = NULL) {

    // turn off compatibility mode as simple xml throws a wobbly if you don't.
    if (ini_get('zend.ze1_compatibility_mode') == 1) {
      ini_set('zend.ze1_compatibility_mode', 0);
    }
    if (is_null($xml)) {
      $xml = simplexml_load_string("<$rootNodeName />");
      $rootNodeName = rtrim($rootNodeName, 's');
    }
    // loop through the data passed in.
    foreach ($data as $key => $value) {

      // no numeric keys in our xml please!
      $numeric = 0;
      if (is_numeric($key)) {
        $numeric = 1;
        $key = $rootNodeName;
      }

      // delete any char not allowed in XML element names
      $key = preg_replace('/[^a-z0-9\-\_\.\:]/i', '', $key);

      // if there is another array found recursively call this function
      if (is_array($value)) {
        $node = (ArrayToXML::isAssoc($value) || $numeric) ? $xml->addChild($key) : $xml;

        // recursive call.
        if ($numeric) {
          $key = 'anon';
        }
        ArrayToXML::toXml($value, $key, $node);
      }
      else {

        // add single node.
        $value = htmlspecialchars(
          html_entity_decode($value, (ENT_QUOTES | ENT_HTML401), 'UTF-8'),
          ENT_NOQUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8', FALSE
        );
        $xml->addChild($key, $value);
      }
    }

    // pass back as XML
    return $xml->asXML();

    // if you want the XML to be formatted, use the below instead to return the XML
    //$doc = new DOMDocument('1.0');
    //$doc->preserveWhiteSpace = false;
    //$doc->loadXML( $xml->asXML() );
    //$doc->formatOutput = true;
    //return $doc->saveXML();
  }


  /**
   * Convert an XML document to a multi dimensional array
   * Pass in an XML document (or SimpleXMLElement object) and this recrusively
   * loops through and builds a representative array
   *
   * @param string $xml - XML document - can optionally be a SimpleXMLElement
   *   object
   *
   * @return array ARRAY
   */
  public static function toArray($xml) {
    if (is_string($xml)) {
      $xml = new SimpleXMLElement($xml);
    }
    $children = $xml->children();
    if (!$children) {
      return (string) $xml;
    }
    $arr = [];
    foreach ($children as $key => $node) {
      $node = ArrayToXML::toArray($node);

      // support for 'anon' non-associative arrays
      if ($key == 'anon') {
        $key = count($arr);
      }

      // if the node is already set, put it into an array
      if (array_key_exists($key, $arr) && isset($arr[$key])) {
        if (!is_array($arr[$key]) || !array_key_exists(0, $arr[$key]) || (array_key_exists(0, $arr[$key]) && ($arr[$key][0] == NULL))) {
          $arr[$key] = [$arr[$key]];
        }
        $arr[$key][] = $node;
      }
      else {
        $arr[$key] = $node;
      }
    }
    return $arr;
  }

  // determine if a variable is an associative array
  public static function isAssoc($array) {
    return (is_array($array) && 0 !== count(array_diff_key($array, array_keys(array_keys($array)))));
  }

}


class XeroException extends Exception {
  protected $retryAfter = NULL;
  public function getRetryAfter(): int {
    return (int) $this->retryAfter;
  }

}

class XeroThrottleException extends XeroException {

  public function __construct($message, $code = NULL, $previous = NULL, ?int $retryAfter = NULL) {
    $this->retryAfter = $retryAfter;
    parent::__construct($message, $code, $previous);
  }
}

class XeroApiException extends XeroException {

  private $xml;

  public function __construct($xml_exception) {
    $this->xml = $xml_exception;
    $xml = new SimpleXMLElement($xml_exception);

    [$message] = $xml->xpath('/ApiException/Message');
    [$errorNumber] = $xml->xpath('/ApiException/ErrorNumber');
    [$type] = $xml->xpath('/ApiException/Type');

    parent::__construct((string) $type . ': ' . (string) $message, (int) $errorNumber);

    $this->type = (string) $type;
  }

  public function getXML() {
    return $this->xml;
  }

  public static function isException($xml) {
    return preg_match('/^<ApiException.*>/', $xml);
  }


}

