<?php

namespace HerokuClient;

use GuzzleHttp\Psr7\Request;
use HerokuClient\Exception\BadHttpStatusException;
use HerokuClient\Exception\JsonDecodingException;
use HerokuClient\Exception\JsonEncodingException;
use HerokuClient\Exception\MissingApiKeyException;
use Http\Client\Curl\Client as CurlHttpClient;
use Http\Client\HttpClient;
use Http\Message\MessageFactory\GuzzleMessageFactory;
use Http\Message\StreamFactory\GuzzleStreamFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Interact with the Heroku Platform API.
 *
 * @see https://devcenter.heroku.com/articles/platform-api-reference
 */
class Client
{

    /**
     * @var string $apiKey  API key or token for use in authentication headers
     */
    protected $apiKey;

    /**
     * @var string baseUrl  Base URL from which all endpoints will be built
     */
    protected $baseUrl = 'https://api.heroku.com/';

    /**
     * @var array $curlOptions  Options to be set when using the default (cURL) HTTP client
     */
    protected $curlOptions = [];

    /**
     * @var HttpClient $httpClient  Client implementing the HTTPlug interface
     */
    protected $httpClient;

    /**
     * @var RequestInterface $lastHttpRequest  PSR-7 Request object from the most recent API call
     */
    protected $lastHttpRequest;

    /**
     * @var ResponseInterface $lastHttpResponse  PSR-7 Response object from the most recent API call
     */
    protected $lastHttpResponse;

    /**
     * Constructor
     *
     * @param array $config  An optional array of class properties and values to be set
     *
     * @throws MissingApiKeyException
     */
    public function __construct(array $config = [])
    {
        // Configure the class as requested.
        foreach ($config as $property => $value) {
            $this->$property = $value;
        }

        // If no API key was configured, try to infer it from the environment.
        if (!$this->apiKey) {
            $this->apiKey = getenv('HEROKU_API_KEY');
        }

        // Require credentials before proceeding.
        if (!$this->apiKey) {
            throw new MissingApiKeyException(
                'Heroku client error: Missing API key. An API key should either be provided ' .
                'at instantiation or available as the HEROKU_API_KEY environmental variable.'
            );
        }

        // Configure a default HTTP client if none was provided.
        if (!$this->httpClient) {
            $this->httpClient = $this->buildHttpClient();
        }
    }

    /**
     * @see Client::execute()  For parameter definitions
     */
    public function get($path, array $headers = [])
    {
        return $this->execute('GET', $path, null, $headers);
    }

    /**
     * @see Client::execute()  For parameter definitions
     */
    public function delete($path, array $headers = [])
    {
        return $this->execute('DELETE', $path, null, $headers);
    }

    /**
     * @see Client::execute()  For parameter definitions
     */
    public function head($path, array $headers = [])
    {
        return $this->execute('HEAD', $path, null, $headers);
    }

    /**
     * @see Client::execute()  For parameter definitions
     */
    public function patch($path, $body = null, array $headers = [])
    {
        return $this->execute('PATCH', $path, $body, $headers);
    }

    /**
     * @see Client::execute()  For parameter definitions
     */
    public function post($path, $body = null, array $headers = [])
    {
        return $this->execute('POST', $path, $body, $headers);
    }

    /**
     * Get the most recent HTTP request.
     *
     * @return RequestInterface  PSR-7 Request object from the most recent API call
     */
    public function getLastHttpRequest()
    {
        return $this->lastHttpRequest;
    }

    /**
     * Get the most recent HTTP response.
     *
     * @return ResponseInterface  PSR-7 Response object from the most recent API call
     */
    public function getLastHttpResponse()
    {
        return $this->lastHttpResponse;
    }

    /**
     * Execute a call against the Heroku Platform API.
     *
     * @param string $method        The HTTP method: DELETE|GET|HEAD|PATCH|POST
     * @param string $path          The API endpoint path
     * @param array|object $body    Optional array or object to be sent in the request body as JSON
     * @param array $customHeaders  Optional array of headers to be set on the request
     * @return \stdClass            JSON-decoded API result
     */
    protected function execute($method, $path, $body = null, array $customHeaders = [])
    {
        // Clear state from the last call.
        $this->lastHttpRequest = null;
        $this->lastHttpResponse = null;

        // Build the request.
        $request = $this->buildRequest($method, $path, $body, $customHeaders);

        // Store the PSR-7 Request object for future examination and use. Redact the API key
        // so it isn't unwittingly propagated as a result of this feature.
        $this->lastHttpRequest = $request->withHeader('Authorization', 'Bearer {REDACTED}');

        // Make the API call.
        $response = $this->httpClient->sendRequest($request);

        // Store the PSR-7 Response object for future examination and use. Heroku uses headers
        // as a secondary communication channel for range, rate limit, and caching information.
        $this->lastHttpResponse = $response;

        return $this->processResponse($response);
    }

    /**
     * Build an API request.
     *
     * @see Client::execute()    For parameter definitions
     * @return RequestInterface  PSR-7 Request object representing the desired interaction
     *
     * @throws JsonEncodingException
     */
    protected function buildRequest($method, $path, $body = null, array $customHeaders = [])
    {
        $headers = [];

        // If a body was included, add it to the request.
        if (isset($body)) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($body);
            // Check for JSON encoding errors.
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new JsonEncodingException(
                    'JSON error while encoding Heroku API request: ' . json_last_error_msg()
                );
            }
        }

        // Add required headers.
        $headers['Accept'] = 'application/vnd.heroku+json; version=3'; // Heroku specifies this.
        $headers['Authorization'] = 'Bearer ' . $this->apiKey;

        // Incorporate any custom headers, preferring them over our defaults.
        $headers = $customHeaders + $headers;

        return new Request($method, $this->baseUrl . $path, $headers, $body);
    }

    /**
     * Build the final return object from the raw HTTP response.
     *
     * @see https://devcenter.heroku.com/articles/platform-api-reference#statuses
     * @see https://devcenter.heroku.com/articles/platform-api-reference#errors
     *
     * @param ResponseInterface $httpResponse  Heroku API response as a PSR-7 Response object
     * @return \stdClass                       JSON-decoded API result
     *
     * @throws BadHttpStatusException
     * @throws JsonDecodingException
     */
    protected function processResponse(ResponseInterface $httpResponse)
    {
        // Attempt to build the API response from the HTTP response body.
        $apiResponse = json_decode($httpResponse->getBody()->getContents());
        $httpResponse->getBody()->rewind(); // Rewind the stream to make future access easier.

        // Check for API errors.
        // @see https://devcenter.heroku.com/articles/platform-api-reference#statuses
        // @see https://devcenter.heroku.com/articles/platform-api-reference#errors
        if ($httpResponse->getStatusCode() >= 400) {
            throw new BadHttpStatusException(sprintf(
                'Heroku API error: HTTP code %s [%s] %s',
                $httpResponse->getStatusCode(),
                empty($apiResponse->id) ? 'no error ID found' : $apiResponse->id,
                empty($apiResponse->message) ? 'no error message found' : $apiResponse->message
            ));
        }

        // Check for JSON decoding errors.
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonDecodingException(
                'JSON error while decoding Heroku API response: ' . json_last_error_msg()
            );
        }

        return $apiResponse;
    }

    /**
     * Build a default HTTP client.
     *
     * @see http://docs.php-http.org/en/latest/clients/curl-client.html#configuring-client
     *
     * @return CurlHttpClient
     */
    protected function buildHttpClient()
    {
        return new CurlHttpClient(
            new GuzzleMessageFactory(),
            new GuzzleStreamFactory(),
            $this->curlOptions
        );
    }
}
