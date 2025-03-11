<?php

namespace Durianpay\Api;

class Api
{
    protected static $baseUrl = 'https://api.durianpay.id/';

    protected static $sandboxBaseUrl = 'https://api-sandbox.durianpay.id/';

    protected static $key = null;

    protected static $secret = null;

    /*
     * App info is to store the Plugin/integration
     * information
     */
    public static $appsDetails = array();

    const VERSION = '2.2.0';

    /**
     * @param string $key
     * @param string $secret
     */
    public function __construct($key, $secret)
    {
        self::$key = $key;
        self::$secret = $secret;
        
        // Check if the secret key has a dp_test prefix and use sandbox URL if it does
        if (strpos($secret, 'dp_test') === 0) {
            self::$baseUrl = self::$sandboxBaseUrl;
        }
    }

    /*
     *  Set Headers
     */
    public function setHeader($header, $value)
    {
        Request::addHeader($header, $value);
    }

    public function setAppDetails($title, $version = null)
    {
        $app = array(
            'title' => $title,
            'version' => $version
        );

        array_push(self::$appsDetails, $app);
    }

    public function getAppsDetails()
    {
        return self::$appsDetails;
    }

    public function setBaseUrl($baseUrl)
    {
        self::$baseUrl = $baseUrl;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        $className = __NAMESPACE__.'\\'.ucwords($name);

        $entity = new $className();

        return $entity;
    }

    public static function getBaseUrl()
    {
        return self::$baseUrl;
    }

    public static function getKey()
    {
        return self::$key;
    }

    public static function getSecret()
    {
        return self::$secret;
    }

    public static function getFullUrl($relativeUrl)
    {
        return self::getBaseUrl() . substr($relativeUrl, 0, -1);
    }

    public static function getAuthHeader() {
		return "Authorization: Basic " . base64_encode(self::getAuthString());
	}

    public static function getAuthString() {
		return self::$secret . ':';
	}
}
