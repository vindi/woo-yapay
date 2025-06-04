<?php

if ( ! class_exists( 'WC_Yapay_Intermediador_Request' ) ) :

/**
 * WooCommerce Vindi Intermediador main class.
 */
include_once("class-wc-yapay_intermediador-requests.php");    
include_once("class-wc-yapay_intermediador-responses.php");    
class WC_Yapay_Intermediador_Request{
    
    public function getUrlEnvironment($environment){
        return ($environment == 'yes') ? "https://api.intermediador.sandbox.yapay.com.br/" : "https://api.intermediador.yapay.com.br/";
    }
    
    public function requestData($pathPost, $dataRequest, $environment = "yes", $strResponse = false, $method = "POST")
    {
        $urlPost = self::getUrlEnvironment($environment).$pathPost;

        $log = new WC_Logger();

        $sanitizedData = $this->sanitizeRequestData($dataRequest);
                
        $log->add( 
            "yapay-intermediador-request-response-", 
            "Vindi NEW REQUEST : \n" . 
            "URL : $urlPost \n" . 
            print_r( $sanitizedData, true ) ."\n\n" 
        );

        $ch = curl_init ( $urlPost );
        
        curl_setopt ( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
        curl_setopt ( $ch, CURLOPT_POST, 1 );
        curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt ( $ch, CURLOPT_POSTFIELDS, $dataRequest);
        curl_setopt ( $ch, CURLOPT_SSLVERSION, 6 );
        // curl_setopt ( $ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2 );
        if ( $method != "POST" ) {
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method) ;
        }

        
        if (!($response = curl_exec($ch))) {
            //Mage::log('Error: Erro na execucao! ', null, 'traycheckout.log');
            if(curl_errno($ch)){
                //Mage::log('Error '.curl_errno($ch).': '. curl_error($ch), null, 'traycheckout.log');
            }else{
                //Mage::log('Error : '. curl_error($ch), null, 'traycheckout.log');
            }
            curl_close ( $ch );
            exit();    
        }

        $log->add( 
            "yapay-intermediador-request-response-", 
            "Vindi NEW RESPONSE : \n" . 
            "URL : $urlPost \n" . 
            print_r( $response, true )
        );
        
        $httpCode = curl_getinfo ( $ch, CURLINFO_HTTP_CODE );
        
        curl_close($ch); 


        $requestData = new WC_Yapay_Intermediador_Requests();

        if (($pathPost == 'v2/transactions/pay_complete') AND ($dataRequest['payment[card_number]'] != null))  {            
            $dataRequest['payment[card_name]'] = '';
            $dataRequest['payment[card_number]'] = '';
            $dataRequest['payment[card_cvv]'] = '';
            $dataRequest['payment[card_expdate_year]'] = '';
            $dataRequest['payment[card_expdate_month]'] = '';
        }
        
        $paramRequests["request_params"] = $dataRequest;
        $paramRequests["request_url"] = $urlPost;
        
        $requestData->addRequest($paramRequests,$environment);

        
        $responseData = new WC_Yapay_Intermediador_Responses();
        
        $paramResponse["response_body"] = $response;
        $paramResponse["response_url"] = $urlPost;
        
        $responseData->addResponse($paramResponse,$environment);
        
        if (!$strResponse AND $pathPost == 'v1/transactions/simulate_splitting'){
            $response = json_decode($response, 1);
        } else if (!$strResponse AND $pathPost == 'v2/transactions/get_by_token') {
            $response = simplexml_load_string($response);
        } else if (!$strResponse AND $pathPost == 'v2/transactions/pay_complete') {
            $response = simplexml_load_string($response);
        } else if (!$strResponse AND $pathPost == 'v3/sales/trace') {
            $response = simplexml_load_string($response);
        } else if (!$strResponse AND $pathPost == 'v1/seller_splits/simulate_split') {
            $response = simplexml_load_string($response);
        }
        
        return $response;
    }

    /**
     * Sanitize sensitive data for logging purposes
     * 
     * @param array $data The original request data
     * @return array Sanitized data safe for logging
     */
    private function sanitizeRequestData($data) {
        $sanitized = $data;
        
        if (isset($sanitized['payment[card_number]'])) {
            $sanitized['payment[card_number]'] = $this->maskCardNumber($sanitized['payment[card_number]']);
        }
        
        if (isset($sanitized['payment[card_cvv]'])) {
            $sanitized['payment[card_cvv]'] = '***';
        }
        
        if (isset($sanitized['payment[card_name]'])) {
            $sanitized['payment[card_name]'] = '[REDACTED]';
        }
        
        if (isset($sanitized['payment[card_expdate_month]'])) {
            $sanitized['payment[card_expdate_month]'] = '**';
        }
        
        if (isset($sanitized['payment[card_expdate_year]'])) {
            $sanitized['payment[card_expdate_year]'] = '****';
        }
        
        if (isset($sanitized['customer[name]'])) {
            $sanitized['customer[name]'] = '[NOME REDACTED]';
        }
        
        if (isset($sanitized['customer[cpf]'])) {
            $sanitized['customer[cpf]'] = $this->maskDocument($sanitized['customer[cpf]']);
        }
        
        if (isset($sanitized['customer[email]'])) {
            $sanitized['customer[email]'] = $this->maskEmail($sanitized['customer[email]']);
        }
        
        foreach ($sanitized as $key => $value) {
            if (strpos($key, 'customer[contacts]') !== false && strpos($key, 'number_contact') !== false) {
                $sanitized[$key] = '(**) *****-****';
            }
        }
        
        foreach ($sanitized as $key => $value) {
            if (strpos($key, 'customer[addresses]') !== false) {
                if (strpos($key, 'postal_code') !== false) {
                    $sanitized[$key] = '*****-***';
                } elseif (strpos($key, 'street') !== false || 
                        strpos($key, 'number') !== false ||
                        strpos($key, 'neighborhood') !== false ||
                        strpos($key, 'completion') !== false) {
                    $sanitized[$key] = '[ENDEREÇO REDACTED]';
                }
            }
        }
        
        if (isset($sanitized['transaction[customer_ip]'])) {
            $sanitized['transaction[customer_ip]'] = $this->maskIP($sanitized['transaction[customer_ip]']);
        }
        
        return $sanitized;
    }

    /**
     * Mask a credit card number to show only first 6 and last 4 digits
     * 
     * @param string $cardNumber The card number to mask
     * @return string The masked card number
     */
    private function maskCardNumber($cardNumber) {
        if (empty($cardNumber)) {
            return '';
        }
        
        $cardNumber = preg_replace('/\D/', '', $cardNumber);
        $length = strlen($cardNumber);
        
        if ($length < 10) {
            return '************';
        }
        
        return substr($cardNumber, 0, 6) . str_repeat('*', $length - 10) . substr($cardNumber, -4);
    }

    /**
     * Mask an email address
     * 
     * @param string $email The email to mask
     * @return string The masked email
     */
    private function maskEmail($email) {
        if (empty($email)) {
            return '';
        }
        
        $parts = explode('@', $email);
        if (count($parts) != 2) {
            return '********@****.***';
        }
        
        $name = $parts[0];
        $domain = $parts[1];
        
        $maskedName = substr($name, 0, 2) . str_repeat('*', strlen($name) - 2);
        $domainParts = explode('.', $domain);
        $maskedDomain = $domainParts[0][0] . str_repeat('*', strlen($domainParts[0]) - 1);
        
        return $maskedName . '@' . $maskedDomain . '.' . end($domainParts);
    }

    /**
     * Mask a document number like CPF
     * 
     * @param string $document The document to mask
     * @return string The masked document
     */
    private function maskDocument($document) {
        if (empty($document)) {
            return '';
        }
        
        $document = preg_replace('/\D/', '', $document);
        
        if (strlen($document) < 4) {
            return str_repeat('*', strlen($document));
        }
        
        return substr($document, 0, 3) . '.***.***-**';
    }

    /**
     * Mask an IP address
     * 
     * @param string $ip The IP address to mask
     * @return string The masked IP
     */
    private function maskIP($ip) {
        if (empty($ip)) {
            return '';
        }
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            return $parts[0] . '.' . $parts[1] . '.*.*';
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return substr($ip, 0, 8) . ':****:****:****:****';
        }
        
        return '[IP REDACTED]';
    }
}
endif;