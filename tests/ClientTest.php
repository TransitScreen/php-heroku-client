<?php

use GuzzleHttp\Psr7\Response;
use HerokuClient\Client as HerokuClient;
use HerokuClient\Exception\BadHttpStatusException;
use HerokuClient\Exception\JsonDecodingException;
use HerokuClient\Exception\JsonEncodingException;
use HerokuClient\Exception\MissingApiKeyException;
use Http\Client\HttpClient;
use Http\Mock\Client as MockHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers HerokuClient\Client
 */
class ClientTest extends TestCase
{

    public function setUp()
    {
        // Make sure an API key exists in the environment.
        putenv('HEROKU_API_KEY=truthyvalue');

        // Create a mock HTTP client that will always return something nice.
        $this->mockHttpClient = new MockHttpClient();
        $this->mockHttpClient->addResponse(
            new Response(200, [], '{}')
        );

        // Create a Heroku client for use by tests that only need a standard one.
        $this->client = new HerokuClient(['httpClient' => $this->mockHttpClient]);
    }

    public function testApiKeyIsInferredFromTheEnvironment()
    {
        // Assert that a client instantiated without an API key infers one from the environment.
        $this->assertAttributeEquals(
            'truthyvalue',
            'apiKey',
            new HerokuClient()
        );
    }

    public function testApiKeyIsRequired()
    {
        $this->expectException(MissingApiKeyException::class);

        // Make sure the client can't infer a key from the environment.
        putenv('HEROKU_API_KEY');

        // Instantiate the client without providing an API key.
        new HerokuClient();
    }

    /**
     * @dataProvider httpMethodsProvider
     */
    public function testHttpMethodWrappers($httpMethod)
    {
        // Attempt a call using the provided HTTP method.
        $this->client->$httpMethod('some/path');

        // Assert the proper method was used.
        $this->assertEquals(
            $httpMethod,
            $this->client->getLastHttpRequest()->getMethod()
        );
    }

    public function testUnencodableRequestJsonThrowsException()
    {
        $this->expectException(JsonEncodingException::class);

        // Try sending the PHP constant for "Not a Number" in a request body.
        $this->client->post('some/path', [NAN]);
    }

    public function testUndecodableResponseJsonThrowsException()
    {
        $this->expectException(JsonDecodingException::class);

        // Create an HTTP client that will always return a bad JSON body.
        $mockHttpClient = new MockHttpClient();
        $mockHttpClient->addResponse(
            new Response(200, [], '{"a": 1 ] }')
        );

        // Attempt an API call.
        (new HerokuClient(['httpClient' => $mockHttpClient]))->get('some/path');
    }

    public function testBadHttpStatusCodeThrowsException()
    {
        $this->expectException(BadHttpStatusException::class);

        // Create an HTTP client that will always return an HTTP 404 error response.
        $mockHttpClient = new MockHttpClient();
        $mockHttpClient->addResponse(new Response(404));

        // Attempt an API call.
        (new HerokuClient(['httpClient' => $mockHttpClient]))->get('some/path');
    }

    public function testBadHttpResponsesCanBeExamined()
    {
        // Create an HTTP client that will always return an HTTP 404 error response.
        $mockHttpClient = new MockHttpClient();
        $mockHttpClient->addResponse(new Response(404));
        $heroku = new HerokuClient(['httpClient' => $mockHttpClient]);

        // Attempt an API call.
        try {
            $heroku->get('some/path');
        } catch (BadHttpStatusException $exception) {
            // Allow execution to continue.
        }

        // Assert that we can read the expected status code from the response.
        $this->assertEquals(
            404,
            $heroku->getLastHttpResponse()->getStatusCode()
        );
    }

    public function testDefaultHttpClientIsCreated()
    {
        // Assert that a suitable HTTP client will be created if none is provided at instantiation.
        $this->assertAttributeInstanceOf(
            HttpClient::class,
            'httpClient',
            new HerokuClient()
        );
    }

    public function testCustomHeadersAreUsed()
    {
        // Attempt an API call.
        $response = $this->client->get(
            'some/path',
            ['TestHeader' => 'TestValue']
        );

        // Assert that our custom header was built into the request.
        $this->assertEquals(
            'TestValue',
            $this->client->getLastHttpRequest()->getHeaderLine('TestHeader')
        );
    }

    public function testApiKeyIsRedacted()
    {
        // Create a client using a custom API key.
        $heroku = new HerokuClient([
            'apiKey' => 'supersecretvalue',
            'httpClient' => $this->mockHttpClient
        ]);

        // Attempt an API call and get the HTTP request used.
        $heroku->get('some/path');
        $httpRequest = $heroku->getLastHttpRequest();

        // Assert that the API key was properly redacted from the Request.
        $this->assertRegExp('/REDACTED/', $httpRequest->getHeaderLine('Authorization'));
        $this->assertNotRegExp('/secret/', $httpRequest->getHeaderLine('Authorization'));
    }

    public function testResponseBodyIsRewound()
    {
        // Attempt an API call.
        $response = $this->client->get('some/path');

        // Assert that the we can access the body without first rewinding the stream.
        $this->assertNotEmpty($this->client->getLastHttpResponse()->getBody()->getContents());
    }

    /**
     * Provides all HTTP methods implemented by this client.
     */
    public function httpMethodsProvider()
    {
        return [
            ['GET'],
            ['DELETE'],
            ['HEAD'],
            ['PATCH'],
            ['POST'],
        ];
    }
}
