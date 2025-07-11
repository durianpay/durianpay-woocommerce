<?php

namespace Durianpay\Api;

use Requests;
use Exception;
use Durianpay\Api\Errors;
use Durianpay\Api\Errors\ErrorCode;

/**
 * Request class to communicate to the request libarary
 */
class Request
{
    /**
     * Headers to be sent with every http request to the API
     * @var array
     */
    protected static $headers = array(
        'Durianpay-API'  =>  1
    );

    /**
     * Fires a request to the API
     * @param  string   $method HTTP Verb
     * @param  string   $url    Relative URL for the request
     * @param  array $data Data to be passed along the request
     * @return array Response data in array format. Not meant
     * to be used directly
     */
    public function request($method, $url, $data = [])
    {
        $url = Api::getFullUrl($url);

        $headers = $this->getRequestHeaders();

        return $this->_request($method, $url, $data, $headers);
    }

    private function _request($method, $url, $payload = [], $headers = []) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);


        if ($method == "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        if (count($headers) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	    curl_setopt($ch, CURLOPT_VERBOSE, true);

        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $output = curl_exec($ch);

        curl_close($ch);

        return json_decode($output, true);
    }

    /**
     * Adds an additional header to all API requests
     * @param string $key   Header key
     * @param string $value Header value
     * @return null
     */
    public static function addHeader($key, $value)
    {
        self::$headers[$key] = $value;
    }

    /**
     * Returns all headers attached so far
     * @return array headers
     */
    public static function getHeaders()
    {
        return self::$headers;
    }

    /**
     * Process the statusCode of the response and throw exception if necessary
     * @param Object $response The response object returned by Requests
     */
    protected function checkErrors($response)
    {
        $body = $response->body;
        $httpStatusCode = $response->status_code;

        try
        {
            $body = json_decode($response->body, true);
        }
        catch (Exception $e)
        {
            $this->throwServerError($body, $httpStatusCode);
        }

        if (($httpStatusCode < 200) or
            ($httpStatusCode >= 300))
        {
            $this->processError($body, $httpStatusCode, $response);
        }
    }

    protected function processError($body, $httpStatusCode, $response)
    {
        $this->verifyErrorFormat($body, $httpStatusCode);

        $code = $body['error']['code'];

        // We are basically converting the error code to the Error class name
        // Replace underscores with space
        // Lowercase the words, capitalize first letter of each word
        // Remove spaces
        $error = str_replace('_', ' ', $code);
        $error = ucwords(strtolower($error));
        $error = str_replace(' ', '', $error);

        // Add namespace
        // This is the fully qualified error class name
        $error = __NAMESPACE__.'\Errors\\' . $error;

        $description = $body['error']['description'];

        $field = null;
        if (isset($body['error']['field']))
        {
            $field = $body['error']['field'];

            // Create an instance of the error and then throw it
            throw new $error($description, $code, $httpStatusCode, $field);
        }

        throw new $error($description, $code, $httpStatusCode);
    }

    protected function throwServerError($body, $httpStatusCode)
    {
        $description = "The server did not send back a well-formed response. " . PHP_EOL .
                       "Server Response: $body";

        throw new Errors\ServerError(
            $description,
            ErrorCode::SERVER_ERROR,
            $httpStatusCode);
    }

    protected function getRequestHeaders()
    {
	$requestHeaders = [
            sprintf("%s", Api::getAuthHeader()),
        ];

        $headers = array_merge(self::$headers, $requestHeaders);

        return $headers;
    }

    protected function constructUa()
    {
        $ua = 'Durianpay/v1 PHPSDK/' . Api::VERSION . ' PHP/' . phpversion();

        $ua .= ' ' . $this->getAppDetailsUa();

        return $ua;
    }

    protected function getAppDetailsUa()
    {
        $appsDetails = Api::$appsDetails;

        $appsDetailsUa = '';

        foreach ($appsDetails as $app)
        {
            if ((isset($app['title'])) and (is_string($app['title'])))
            {
                $appUa = $app['title'];

                if ((isset($app['version'])) and (is_scalar($app['version'])))
                {
                    $appUa .= '/' . $app['version'];
                }

                $appsDetailsUa .= $appUa . ' ';
            }
        }

        return $appsDetailsUa;
    }

    /**
     * Verifies error is in proper format. If not then
     * throws ServerErrorException
     *
     * @param  array $body
     * @param  int $httpStatusCode
     * @return void
     */
    protected function verifyErrorFormat($body, $httpStatusCode)
    {
        if (is_array($body) === false)
        {
            $this->throwServerError($body, $httpStatusCode);
        }

        if ((isset($body['error']) === false) or
            (isset($body['error']['code']) === false))
        {
            $this->throwServerError($body, $httpStatusCode);
        }

        $code = $body['error']['code'];

        if (Errors\ErrorCode::exists($code) === false)
        {
            $this->throwServerError($body, $httpStatusCode);
        }
    }
}