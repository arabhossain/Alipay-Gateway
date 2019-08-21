<?php

class Alipay
{

    /**
     * The application Partner ID
     *
     * @var string $_partner_id
     */
    private $_partner_id = "";

    /**
     * The application Private Key
     *
     * @var string $_key
     */
    private $_key = "";

    /**
     * The Alipay API endpoint
     *
     * @var string $_endpoint
     */
    private $_endpoint = "";

    /**
     * Instantiates connection vars
     *
     * @return void
     */
    public function __construct($data)
    {
        $this->_partner_id = $data['partner_id'];// "2088101122136241";
        $this->_key        = $data['partner_key'];// "760bdzec6y9goq7ctyx96ezkz78287de";
        $this->_endpoint   = ($data['is_test_account'])? "https://openapi.alipaydev.com/gateway.do" : "https://mapi.alipay.com/gateway.do";


    }

    /**
     * We create a transaction URL for Alipay. There are two types of response
     * handlers. In some cases a transaction is delayed from being completed
     * while it's being verified by Alipay; these ping notify_url later.
     *
     * return_url
     * Alipay sends the buyer back to this URL synchronously, along with a GET response.
     *
     * notify_url
     * A POST response is sent asynchronously once the payment is verified. A
     * 'notify_id' will be in the response that needs to be verfied by calling
     * verifyPayment. The 'notify_id' expires in 1 minute.
     *
     * @param string $sale_id
     * @param int $amount
     * @param string $currency
     * @param string $description
     * @param string $return_url
     * @param string $notify_url
     * @return string
     */
    public function createPayment($sale_id = "" , $amount = 0 , $currency = "USD" , $description = "" , $return_url = "" , $notify_url = "")
    {
        $data = [
            'body'           => $description ,
            'service'        => is_mobile()? 'create_forex_trade_wap' : 'create_forex_trade' ,
            'out_trade_no'   => $sale_id ,
            'currency'       => $currency ,
            'total_fee'      => $amount ,
            'subject'        => $description ,
            'return_url'     => $return_url ,
            'notify_url'     => $notify_url ,
            'partner'        => $this->_partner_id ,
            '_input_charset' => "utf-8"
        ];

        return $this->_endpoint . "?" . $this->_prepData($data);
    }

    /**
     * Compares the signed response data from Alipay with our own key
     * using the response parameters. We also verify the transaction by using
     * the 'notify_id' and pinging Alipay again.
     *
     * Possible Trade Status:
     * WAIT_BUYER_PAY
     * TRADE_CLOSED
     * TRADE_FINISHED
     *
     * @param array $data The response parameters from Alipay
     * @return boolean
     **@throws AlipayException
     * @throws Exception
     */
    public function verifyPayment($data = array())
    {
        $sign = $data['sign'];
        unset($data['sign'] , $data['sign_type']);
        $new_sign = $this->_sign($data);
        if ( $sign != $new_sign ) {
            throw new AlipayException("Hashes do not match: $sign - $new_sign (Transaction #{$data['out_trade_no']}).");
        }
        $request = [
            'service'   => 'notify_verify' ,
            'partner'   => $this->_partner_id ,
            'notify_id' => $data['notify_id']
        ];

        $response = $this->_send(http_build_query($request) , "GET");
        if ( preg_match("/true$/i" , $response) ) {
            if ( $data['trade_status'] == "TRADE_FINISHED" ) {
                return true;
            }
        }else{
            throw new AlipayException("Alipay transaction is invalid (Transaction #{$data['out_trade_no']}).");
        }
        return false;
    }

    /**
     * Send a request
     *
     * @param array $data The payload
     * @param string $method The request method: POST, GET
     * @return string
     * @throws Exception
     */
    private function _send($data = array() , $method = "GET")
    {
        $method = strtoupper($method);
        if ( $method == "GET" ) {
            $curl = curl_init($this->_endpoint . "?$data");
            curl_setopt($curl , CURLOPT_POST , false);
        }else{
            $curl = curl_init($this->_endpoint . "?_input_charset=utf-8");
            curl_setopt($curl , CURLOPT_POST , true);
        }
        curl_setopt($curl , CURLOPT_HEADER , 0);
        curl_setopt($curl , CURLOPT_RETURNTRANSFER , 1);
        curl_setopt($curl , CURLOPT_SSLVERSION , 3);
        curl_setopt($curl , CURLOPT_SSL_VERIFYPEER , true);
        curl_setopt($curl , CURLOPT_SSL_VERIFYHOST , 2);
        curl_setopt($curl , CURLOPT_CAINFO , "./alipay_ca.pem");
        $response = curl_exec($curl);
        $error    = curl_error($curl);
        curl_close($curl);

        if ( $error ) {
            throw new Exception($error);
        }
        return $response;
    }

    /**
     * Prepares a request for delivery by building and encoding the query.
     *
     * @param array $data Associative array of request parameters
     * @return string
     */
    private function _prepData($data = array())
    {
        $data['sign']      = $this->_sign($data);
        $data['sign_type'] = "MD5";
        ksort($data);
        return http_build_query($data);
    }

    /**
     * Sorts the parameters alphabetically and creates a "secure" hash with the
     * secret key appended. When Alipay receives the request, they perform a
     * similar procedure to verify the data has not been tampered with.
     *
     * @param array $data Associative array of request parameters
     * @return string
     */
    private function _sign($data = array())
    {
        ksort($data);
        $query = "";
        foreach ($data as $k => $v){
            if ( $v == "" )
                continue;
            $query .= "$k=$v&";
        }
        return md5(substr($query , 0 , -1) . $this->_key);
    }

    public function uuid()
    {
        $data    = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0010
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s' , str_split(bin2hex($data) , 4));
    }
}
