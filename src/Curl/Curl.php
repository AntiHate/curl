<?php

namespace Curl;

/**
 * An object-oriented wrapper of the PHP cURL extension.
 *
 * This library requires to have the php cURL extensions installed:
 * https://php.net/manual/curl.setup.php
 *
 * Example of making a get request with parameters:
 *
 * ```php
 * $curl = new Curl\Curl();
 * $curl->get('http://www.example.com/search', array(
 *     'q' => 'keyword',
 * ));
 * ```
 *
 * Example post request with post data:
 *
 * ```php
 * $curl = new Curl\Curl();
 * $curl->post('http://www.example.com/login/', array(
 *     'username' => 'myusername',
 *     'password' => 'mypassword',
 * ));
 * ```
 *
 * @see https://php.net/manual/curl.setup.php
 */
class Curl
{
    // The HTTP authentication method(s) to use.

    /**
     * @var int Type AUTH_BASIC
     */
    const AUTH_BASIC = CURLAUTH_BASIC;

    /**
     * @var int Type AUTH_DIGEST
     */
    const AUTH_DIGEST = CURLAUTH_DIGEST;

    /**
     * @var int Type AUTH_GSSNEGOTIATE
     */
    const AUTH_GSSNEGOTIATE = CURLAUTH_GSSNEGOTIATE;

    /**
     * @var int Type AUTH_NTLM
     */
    const AUTH_NTLM = CURLAUTH_NTLM;

    /**
     * @var int Type AUTH_ANY
     */
    const AUTH_ANY = CURLAUTH_ANY;

    /**
     * @var int Type AUTH_ANYSAFE
     */
    const AUTH_ANYSAFE = CURLAUTH_ANYSAFE;

    /**
     * @var string The user agent name which is set when making a request
     */
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private $_cookies = array();

    private $_headers = array();

    /**
     * @var resource Contains the curl resource created by `curl_init()` function
     */
    public $curl;

    /**
     * @var bool Whether an error occurred or not
     */
    public $error = false;

    /**
     * @var int Contains the error code of the current request, 0 means no error happened
     */
    public $error_code = 0;

    /**
     * @var string If the curl request failed, the error message is contained
     */
    public $error_message = null;

    /**
     * @var bool Whether an error occurred or not
     */
    public $curl_error = false;

    /**
     * @var int Contains the error code of the current request, 0 means no error happened.
     * @see https://curl.haxx.se/libcurl/c/libcurl-errors.html
     */
    public $curl_error_code = 0;

    /**
     * @var string If the curl request failed, the error message is contained
     */
    public $curl_error_message = null;

    /**
     * @var bool Whether an error occurred or not
     */
    public $http_error = false;

    /**
     * @var int Contains the status code of the current processed request.
     */
    public $http_status_code = 0;

    /**
     * @var string If the curl request failed, the error message is contained
     */
    public $http_error_message = null;

    /**
     * @var string|array TBD (ensure type) Contains the request header information
     */
    public $request_headers = null;

    /**
     * @var string|array TBD (ensure type) Contains the response header information
     */
    public $response_headers = array();

    /**
     * @var string|false|null Contains the response from the curl request
     */
    public $response = null;

    /**
     * @var bool Whether the current section of response headers is after 'HTTP/1.1 100 Continue'
     */
    protected $response_header_continue = false;

    /**
     * Constructor ensures the available curl extension is loaded.
     *
     * @throws \RuntimeException
     */
    public function __construct()
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The cURL extensions is not loaded, make sure you have installed the cURL extension: https://php.net/manual/curl.setup.php');
        }

        $this->init();
    }

    // private methods

    /**
     * Initializer for the curl resource.
     *
     * Is called by the __construct() of the class or when the curl request is reset.
     * @return self
     */
    private function init()
    {
        $this->curl = curl_init();
        $this->setUserAgent(self::USER_AGENT);
        $this->setOpt(CURLINFO_HEADER_OUT, true);
        $this->setOpt(CURLOPT_HEADER, false);
        $this->setOpt(CURLOPT_RETURNTRANSFER, true);
        $this->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $this->setOpt(CURLOPT_SSL_VERIFYPEER, false);
        $this->setOpt(CURLOPT_SSL_VERIFYHOST, false);

        return $this;
    }

    /**
     * Handle writing the response headers
     *
     * @param resource $curl The current curl resource
     * @param string $header_line A line from the list of response headers
     *
     * @return int Returns the length of the $header_line
     */
    public function addResponseHeaderLine($curl, $header_line)
    {
        $trimmed_header = trim($header_line, "\r\n");

        if ($trimmed_header === "") {
            $this->response_header_continue = false;
        } elseif (strtolower($trimmed_header) === 'http/1.1 100 continue') {
            $this->response_header_continue = true;
        } elseif (!$this->response_header_continue) {
            $this->response_headers[] = $trimmed_header;
        }

        return strlen($header_line);
    }

    // protected methods

    /**
     * Execute the curl request based on the respective settings.
     *
     * @return int Returns the error code for the current curl request
     */
    public function exec()
    {
        $this->setCookieFile();
        $this->setHeader('Accept', '*/*');
        $this->setHeader('Connection', 'Keep-Alive');

        $this->setOpt(CURLOPT_HEADERFUNCTION, array($this, 'addResponseHeaderLine'));
        $this->response_headers = array();
        $this->response = curl_exec($this->curl);
        $this->curl_error_code = curl_errno($this->curl);
        $this->curl_error_message = curl_error($this->curl);
        $this->curl_error = !($this->getErrorCode() === 0);
        $this->http_status_code = intval(curl_getinfo($this->curl, CURLINFO_HTTP_CODE));
        $this->http_error = $this->isError();
        $this->error = $this->curl_error || $this->http_error;
        $this->error_code = $this->error ? ($this->curl_error ? $this->getErrorCode() : $this->getHttpStatus()) : 0;
        $this->request_headers = preg_split('/\r\n/', curl_getinfo($this->curl, CURLINFO_HEADER_OUT), -1, PREG_SPLIT_NO_EMPTY);
        $this->http_error_message = $this->error ? (isset($this->response_headers['0']) ? $this->response_headers['0'] : '') : '';
        $this->error_message = $this->curl_error ? $this->getErrorMessage() : $this->http_error_message;
        $this->setOpt(CURLOPT_HEADERFUNCTION, null);
        return $this->error_code;
    }

    /**
     * @param array|object|string $data
     */
    protected function preparePayload($data)
    {
        $this->setOpt(CURLOPT_POST, true);

        $data = http_build_query($data);

        $this->setOpt(CURLOPT_POSTFIELDS, $data);
    }
    /**
     * @param array|object|string $data
     */

     /**
     *
     */
    protected function setCookieFile()
    {
      $cookieFile = "/tmp/".parse_url( curl_getinfo($this->curl)['url'] )['host']."_cookie.txt";

      $this->setOpt(CURLOPT_COOKIEFILE, $cookieFile);
      $this->setOpt(CURLOPT_COOKIEJAR, $cookieFile);
    }

    /**
     * @param array|object|string $data
     */

     protected function preparePayloadOld($data)
    {
        $this->setOpt(CURLOPT_POST, true);

        if (is_array($data) || is_object($data)) {
            $skip = false;
            foreach ($data as $key => $value) {
                // If a value is an instance of CurlFile skip the http_build_query
                // see issue https://github.com/php-mod/curl/issues/46
                // suggestion from: https://stackoverflow.com/a/36603038/4611030
                if ($value instanceof \CurlFile) {
                    $skip = true;
                }
            }

            if (!$skip) {
                $data = http_build_query($data);
            }
        }

        $this->setOpt(CURLOPT_POSTFIELDS, $data);
    }

    /**
     * Set the json payload informations to the postfield curl option.
     *
     * @param array $data The data to be sent.
     * @return void
     */
    protected function prepareJsonPayload(array $data)
    {
        $this->setOpt(CURLOPT_POST, true);
        $this->setOpt(CURLOPT_POSTFIELDS, json_encode($data));
    }

    /**
     * Set auth options for the current request.
     *
     * Available auth types are:
     *
     * + self::AUTH_BASIC
     * + self::AUTH_DIGEST
     * + self::AUTH_GSSNEGOTIATE
     * + self::AUTH_NTLM
     * + self::AUTH_ANY
     * + self::AUTH_ANYSAFE
     *
     * @param int $httpauth The type of authentication
     */
    protected function setHttpAuth($httpauth)
    {
        $this->setOpt(CURLOPT_HTTPAUTH, $httpauth);
    }

    // functions

    /**
     * Make a get request with optional data.
     *
     * The get request has no body data, the data will be correctly added to the $url with the http_build_query() method.
     *
     * @param string $url  The url to make the get request for
     * @param array  $data Optional arguments who are part of the url
     * @return self
     */
    public function get($url, $data = array())
    {
        // $this->setOpt(CURLOPT_CUSTOMREQUEST, "GET");
        if (count($data) > 0) {
            $this->setOpt(CURLOPT_URL, $url.'?'.http_build_query($data));
        } else {
            $this->setOpt(CURLOPT_URL, $url);
        }
        $this->setOpt(CURLOPT_HTTPGET, true);
        $this->exec();
        return $this;
    }

    /**
     * Download file with curl file handle option
     *
     * The get request has no body data, the data will be correctly added to the $url with the http_build_query() method.
     *
     * @param string $url  The url to make the get request for
     * @param array  $data Optional arguments who are part of the url
     * @param string $path  The absolute path to save the file. filename will be extracted from the url.
     * @param string $filename  override filename
     * @return self
     */
    public function download($url, $path, $filename = null, $data = [])
    {
        // $this->setOpt(CURLOPT_CUSTOMREQUEST, "GET");
        if (count($data) > 0) {
            $this->setOpt(CURLOPT_URL, $url.'?'.http_build_query($data));
        } else {
            $this->setOpt(CURLOPT_URL, $url);
        }
        $this->setOpt(CURLOPT_HTTPGET, true);

        if(is_null($filename))
          $filePath = $path.'/'.basename($url);
        else
          $filePath = $path.'/'.$filename;

        $file_handle = fopen($filePath, 'w+');
        if ($file_handle == FALSE){
          $this->error = true;
          $this->error_message = "Unable to open file.";
          return $this;
        }

        $this->setOpt(CURLOPT_FILE, $file_handle);

        $this->exec();
        // disable writing to file
        $this->setOpt(CURLOPT_FILE, null);
        // close the file for writing
        fclose($file_handle);

        return $this;
    }

    /**
     * Download file using fwrite file method
     *
     * The get request has no body data, the data will be correctly added to the $url with the http_build_query() method.
     *
     * @param string $url  The url to make the get request for
     * @param array  $data Optional arguments who are part of the url
     * @param string $path  The absolute path to save the file. filename will be extracted from the url.
     * @param string $filename  override filename
     * @return self
     */
    public function downloadFwrite($url, $path, $filename = null, $data = [])
    {
        // $this->setOpt(CURLOPT_CUSTOMREQUEST, "GET");
        if (count($data) > 0) {
            $this->setOpt(CURLOPT_URL, $url.'?'.http_build_query($data));
        } else {
            $this->setOpt(CURLOPT_URL, $url);
        }
        $this->setOpt(CURLOPT_HTTPGET, true);

        if(is_null($filename))
          $filePath = $path.'/'.basename($url);
        else
          $filePath = $path.'/'.$filename;

        $file_handle = fopen($filePath, 'w+');
        if ($file_handle == FALSE){
          $this->error = true;
          $this->error_message = "Unable to open file.";
          return $this;
        }

        $this->exec();

        fwrite($file_handle, $this->response);
        fclose($file_handle);

        return $this;
    }

    /**
     * Purge Request
     *
     * A very common scenario to send a purge request is within the use of varnish, therefore
     * the optional hostname can be defined.
     *
     * @param string $url The url to make the purge request
     * @param string $hostName An optional hostname which will be sent as http host header
     * @return self
     * @since 2.4.0
     */
    public function purge($url, $hostName = null)
    {
        $this->setOpt(CURLOPT_URL, $url);
        $this->setOpt(CURLOPT_CUSTOMREQUEST, 'PURGE');
        if ($hostName) {
            $this->setHeader('Host', $hostName);
        }
        $this->exec();
        return $this;
    }

    /**
     * Make a post request with optional post data.
     *
     * @param string $url  The url to make the post request
     * @param array|object|string $data Post data to pass to the url
     * @param boolean $asJson Whether the data should be passed as json or not. {@insce 2.2.1}
     * @return self
     */
    public function post($url, $data = array(), $asJson = false)
    {
        // If enabled it doesn't work with some ASP websites, need to disbale with all GET/POST request
        // PUT, DELETE request not checked
        // $this->setOpt(CURLOPT_CUSTOMREQUEST, "POST");
        $this->setOpt(CURLOPT_URL, $url);
        if ($asJson) {
            $this->prepareJsonPayload($data);
        } else {
            $this->preparePayload($data);
        }
        $this->exec();
        return $this;
    }

    /**
     * Make a put request with optional data.
     *
     * The put request data can be either sent via payload or as get parameters of the string.
     *
     * @param string $url The url to make the put request
     * @param array $data Optional data to pass to the $url
     * @param bool $payload Whether the data should be transmitted trough payload or as get parameters of the string
     * @param boolean $asJson Whether the data should be passed as json or not. {@insce 2.4.0}
     * @return self
     */
    public function put($url, $data = array(), $payload = false, $asJson = false)
    {
        if (! empty($data)) {
            if ($payload === false) {
                $url .= '?'.http_build_query($data);
            } else {
                if ($asJson) {
                    $this->prepareJsonPayload($data);
                } else {
                    $this->preparePayload($data);
                }
            }
        }

        $this->setOpt(CURLOPT_URL, $url);
        $this->setOpt(CURLOPT_CUSTOMREQUEST, 'PUT');
        $this->exec();
        return $this;
    }

    /**
     * Make a patch request with optional data.
     *
     * The patch request data can be either sent via payload or as get parameters of the string.
     *
     * @param string $url The url to make the patch request
     * @param array $data Optional data to pass to the $url
     * @param bool $payload Whether the data should be transmitted trough payload or as get parameters of the string
     * @param boolean $asJson Whether the data should be passed as json or not. {@insce 2.4.0}
     * @return self
     */
    public function patch($url, $data = array(), $payload = false, $asJson = false)
    {
        if (! empty($data)) {
            if ($payload === false) {
                $url .= '?'.http_build_query($data);
            } else {
                if ($asJson) {
                    $this->prepareJsonPayload($data);
                } else {
                    $this->preparePayload($data);
                }
            }
        }

        $this->setOpt(CURLOPT_URL, $url);
        $this->setOpt(CURLOPT_CUSTOMREQUEST, 'PATCH');
        $this->exec();
        return $this;
    }

    /**
     * Make a delete request with optional data.
     *
     * @param string $url The url to make the delete request
     * @param array $data Optional data to pass to the $url
     * @param bool $payload Whether the data should be transmitted trough payload or as get parameters of the string
     * @return self
     */
    public function delete($url, $data = array(), $payload = false)
    {
        if (! empty($data)) {
            if ($payload === false) {
                $url .= '?'.http_build_query($data);
            } else {
                $this->preparePayload($data);
            }
        }

        $this->setOpt(CURLOPT_URL, $url);
        $this->setOpt(CURLOPT_CUSTOMREQUEST, 'DELETE');
        $this->exec();
        return $this;
    }

    // setters

    /**
     * Pass basic auth data.
     *
     * If the the requested url is secured by an htaccess basic auth mechanism you can use this method to provided the auth data.
     *
     * ```php
     * $curl = new Curl();
     * $curl->setBasicAuthentication('john', 'doe');
     * $curl->get('http://example.com/secure.php');
     * ```
     *
     * @param string $username The username for the authentication
     * @param string $password The password for the given username for the authentication
     * @return self
     */
    public function setBasicAuthentication($username, $password)
    {
        $this->setHttpAuth(self::AUTH_BASIC);
        $this->setOpt(CURLOPT_USERPWD, $username.':'.$password);
        return $this;
    }

    /**
     * Provide optional header information.
     *
     * In order to pass optional headers by key value pairing:
     *
     * ```php
     * $curl = new Curl();
     * $curl->setHeader('X-Requested-With', 'XMLHttpRequest');
     * $curl->get('http://example.com/request.php');
     * ```
     *
     * @param string $key   The header key
     * @param string $value The value for the given header key
     * @return self
     */
    public function setHeader($key, $value)
    {
        $this->_headers[$key] = $key.': '.$value;
        $this->setOpt(CURLOPT_HTTPHEADER, array_values($this->_headers));
        return $this;
    }

    /**
     * Provide a User Agent.
     *
     * In order to provide you customized user agent name you can use this method.
     *
     * ```php
     * $curl = new Curl();
     * $curl->setUserAgent('My John Doe Agent 1.0');
     * $curl->get('http://example.com/request.php');
     * ```
     *
     * @param string $useragent The name of the user agent to set for the current request
     * @return self
     */
    public function setUserAgent($useragent)
    {
        $this->setOpt(CURLOPT_USERAGENT, $useragent);
        return $this;
    }

    /**
     * Set the HTTP referer header.
     *
     * The $referer Information can help identify the requested client where the requested was made.
     *
     * @param string $referer An url to pass and will be set as referer header
     * @return self
     */
    public function setReferer($referer)
    {
        $this->setOpt(CURLOPT_REFERER, $referer);
        return $this;
    }

    /**
     * Set contents of HTTP Cookie header.
     *
     * @param string $key   The name of the cookie
     * @param string $value The value for the provided cookie name
     * @return self
     */
    public function setCookie($key, $value)
    {
        $this->_cookies[$key] = $value;
        $this->setOpt(CURLOPT_COOKIE, http_build_query($this->_cookies, '', '; '));
        return $this;
    }

    /**
     * Set customized curl options.
     *
     * To see a full list of options: http://php.net/curl_setopt
     *
     * @see http://php.net/curl_setopt
     * @param int $option The curl option constant e.g. `CURLOPT_AUTOREFERER`, `CURLOPT_COOKIESESSION`
     * @param mixed $value The value to pass for the given $option
     * @return bool
     */
    public function setOpt($option, $value)
    {
        return curl_setopt($this->curl, $option, $value);
    }

    /**
     * Get curl option for a certain name
     *
     * To see a full list of options: http://php.net/curl_getinfo
     *
     * @see http://php.net/curl_getinfo
     * @param int $option The curl option constant e.g. `CURLOPT_AUTOREFERER`, `CURLOPT_COOKIESESSION`
     * @return mixed
     */
    public function getOpt($option)
    {
        return curl_getinfo($this->curl, $option);
    }

    /**
     * Return the all options for current curl ressource
     *
     * To see a full list of options: http://php.net/curl_getinfo
     *
     * @see http://php.net/curl_getinfo
     * @return array
     * @since 2.5.0
     */
    public function getOpts()
    {
        return curl_getinfo($this->curl);
    }

    /**
    * Return the endpoint set for curl
    *
    * @see http://php.net/curl_getinfo
    * @return string of endpoint
    */
    public function getEndpoint()
    {
        return $this->getOpt(CURLINFO_EFFECTIVE_URL);
    }

    /**
     * Enable verbosity.
     *
     * @param bool $on
     * @return self
     */
    public function setVerbose($on = true)
    {
        $this->setOpt(CURLOPT_VERBOSE, $on);
        return $this;
    }

    /**
     * Reset all curl options.
     *
     * In order to make multiple requests with the same curl object all settings requires to be reset.
     * @return self
     */
    public function reset()
    {
        $this->close();
        $this->_cookies = array();
        $this->_headers = array();
        $this->error = false;
        $this->error_code = 0;
        $this->error_message = null;
        $this->curl_error = false;
        $this->curl_error_code = 0;
        $this->curl_error_message = null;
        $this->http_error = false;
        $this->http_status_code = 0;
        $this->http_error_message = null;
        $this->request_headers = null;
        $this->response_headers = array();
        $this->response = false;
        $this->init();
        return $this;
    }

    /**
     * Closing the current open curl resource.
     * @return self
     */
    public function close()
    {
        if (is_resource($this->curl)) {
            curl_close($this->curl);
        }
        return $this;
    }

    /**
     * Close the connection when the Curl object will be destroyed.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Was an 'info' header returned.
     * @return bool
     */
    public function isInfo()
    {
        return $this->getHttpStatus() >= 100 && $this->getHttpStatus() < 200;
    }

    /**
     * Was an 'OK' response returned.
     * @return bool
     */
    public function isSuccess()
    {
        return $this->getHttpStatus() >= 200 && $this->getHttpStatus() < 300 && !$this->error;
    }

    /**
     * Was a 'redirect' returned.
     * @return bool
     */
    public function isRedirect()
    {
        return $this->getHttpStatus() >= 300 && $this->getHttpStatus() < 400;
    }

    /**
     * Was an 'error' returned (client error or server error).
     * @return bool
     */
    public function isError()
    {
        return $this->getHttpStatus() >= 400 && $this->getHttpStatus() < 600 || $this->error;
    }

    /**
     * Was a 'client error' returned.
     * @return bool
     */
    public function isClientError()
    {
        return $this->getHttpStatus() >= 400 && $this->getHttpStatus() < 500;
    }

    /**
     * Was a 'server error' returned.
     * @return bool
     */
    public function isServerError()
    {
        return $this->getHttpStatus() >= 500 && $this->getHttpStatus() < 600;
    }

    /**
     * Get a specific response header key or all values from the response headers array.
     *
     * Usage example:
     *
     * ```php
     * $curl = (new Curl())->get('http://example.com');
     *
     * echo $curl->getResponseHeaders('Content-Type');
     * ```
     *
     * Or in order to dump all keys with the given values use:
     *
     * ```php
     * $curl = (new Curl())->get('http://example.com');
     *
     * var_dump($curl->getResponseHeaders());
     * ```
     *
     * @param string $headerKey Optional key to get from the array.
     * @return bool|string|array
     * @since 1.9
     */
    public function getResponseHeaders($headerKey = null)
    {
        $headers = array();
        if (!is_null($headerKey)) {
            $headerKey = strtolower($headerKey);
        }

        foreach ($this->response_headers as $header) {
            $parts = explode(":", $header, 2);

            $key = isset($parts[0]) ? $parts[0] : '';
            $value = isset($parts[1]) ? $parts[1] : '';

            $headers[trim(strtolower($key))] = trim($value);
        }

        if ($headerKey) {
            return isset($headers[$headerKey]) ? $headers[$headerKey] : false;
        }

        return $headers;
    }

    /**
     * Get response from the curl request
     * @return string|false
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Get curl error code
     * @return int
     */
    public function getErrorCode()
    {
        return $this->curl_error_code;
    }

    /**
     * Get curl error message
     * @return string
     */
    public function getErrorMessage()
    {
        return $this->curl_error_message ? $this->curl_error_message : $this->error_message;
    }

    /**
     * Get Content-Type from response headers
     * @return string
     */
    public function getContentType()
    {
        return $this->getResponseHeaders('Content-Type');
    }

    /**
     * Get http status code from the curl request
     * @return int
     */
    public function getHttpStatus()
    {
        return $this->http_status_code;
    }
}
