<?php

namespace React\Tests\Http;

use React\Http\RequestHeaderParser;

class RequestHeaderParserTest extends TestCase
{
    public function testSplitShouldHappenOnDoubleCrlf()
    {
        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());

        $parser->feed("GET / HTTP/1.1\r\n");
        $parser->feed("Host: example.com:80\r\n");
        $parser->feed("Connection: close\r\n");

        $parser->removeAllListeners();
        $parser->on('headers', $this->expectCallableOnce());

        $parser->feed("\r\n");
    }

    public function testFeedInOneGo()
    {
        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableOnce());

        $data = $this->createGetRequest();
        $parser->feed($data);
    }

    public function testHeadersEventShouldReturnRequestAndBodyBuffer()
    {
        $request = null;
        $bodyBuffer = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', function ($parsedRequest, $parsedBodyBuffer) use (&$request, &$bodyBuffer) {
            $request = $parsedRequest;
            $bodyBuffer = $parsedBodyBuffer;
        });

        $data = $this->createGetRequest();
        $data .= 'RANDOM DATA';
        $parser->feed($data);

        $this->assertInstanceOf('Psr\Http\Message\RequestInterface', $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertEquals('http://example.com/', $request->getUri());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame(array('Host' => array('example.com:80'), 'Connection' => array('close')), $request->getHeaders());

        $this->assertSame('RANDOM DATA', $bodyBuffer);
    }

    public function testHeadersEventShouldReturnBinaryBodyBuffer()
    {
        $bodyBuffer = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', function ($parsedRequest, $parsedBodyBuffer) use (&$bodyBuffer) {
            $bodyBuffer = $parsedBodyBuffer;
        });

        $data = $this->createGetRequest();
        $data .= "\0x01\0x02\0x03\0x04\0x05";
        $parser->feed($data);

        $this->assertSame("\0x01\0x02\0x03\0x04\0x05", $bodyBuffer);
    }

    public function testHeadersEventShouldParsePathAndQueryString()
    {
        $request = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', function ($parsedRequest, $parsedBodyBuffer) use (&$request) {
            $request = $parsedRequest;
        });

        $data = $this->createAdvancedPostRequest();
        $parser->feed($data);

        $this->assertInstanceOf('Psr\Http\Message\RequestInterface', $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertEquals('http://example.com/foo?bar=baz', $request->getUri());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $headers = array(
            'Host' => array('example.com:80'),
            'User-Agent' => array('react/alpha'),
            'Connection' => array('close'),
        );
        $this->assertSame($headers, $request->getHeaders());
    }

    public function testHeaderOverflowShouldEmitError()
    {
        $error = null;
        $passedParser = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message, $parser) use (&$error, &$passedParser) {
            $error = $message;
            $passedParser = $parser;
        });

        $this->assertSame(1, count($parser->listeners('headers')));
        $this->assertSame(1, count($parser->listeners('error')));

        $data = str_repeat('A', 4097);
        $parser->feed($data);

        $this->assertInstanceOf('OverflowException', $error);
        $this->assertSame('Maximum header size of 4096 exceeded.', $error->getMessage());
        $this->assertSame($parser, $passedParser);
        $this->assertSame(0, count($parser->listeners('headers')));
        $this->assertSame(0, count($parser->listeners('error')));
    }

    public function testHeaderOverflowShouldNotEmitErrorWhenDataExceedsMaxHeaderSize()
    {
        $request = null;
        $bodyBuffer = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', function ($parsedRequest, $parsedBodyBuffer) use (&$request, &$bodyBuffer) {
            $request = $parsedRequest;
            $bodyBuffer = $parsedBodyBuffer;
        });

        $data = $this->createAdvancedPostRequest();
        $body = str_repeat('A', 4097 - strlen($data));
        $data .= $body;

        $parser->feed($data);

        $headers = array(
            'Host' => array('example.com:80'),
            'User-Agent' => array('react/alpha'),
            'Connection' => array('close'),
        );
        $this->assertSame($headers, $request->getHeaders());

        $this->assertSame($body, $bodyBuffer);
    }

    public function testGuzzleRequestParseException()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $this->assertSame(1, count($parser->listeners('headers')));
        $this->assertSame(1, count($parser->listeners('error')));

        $parser->feed("\r\n\r\n");

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('Invalid message', $error->getMessage());
        $this->assertSame(0, count($parser->listeners('headers')));
        $this->assertSame(0, count($parser->listeners('error')));
    }

    public function testInvalidAbsoluteFormSchemeEmitsError()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $parser->feed("GET tcp://example.com:80/ HTTP/1.0\r\n\r\n");

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('Invalid absolute-form request-target', $error->getMessage());
    }

    public function testInvalidAbsoluteFormWithFragmentEmitsError()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $parser->feed("GET http://example.com:80/#home HTTP/1.0\r\n\r\n");

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('Invalid absolute-form request-target', $error->getMessage());
    }

    public function testInvalidHostHeaderForHttp11()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $parser->feed("GET / HTTP/1.1\r\nHost: a/b/c\r\n\r\n");

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame('Invalid Host header for HTTP/1.1 request', $error->getMessage());
    }

    public function testInvalidHttpVersion()
    {
        $error = null;

        $parser = new RequestHeaderParser();
        $parser->on('headers', $this->expectCallableNever());
        $parser->on('error', function ($message) use (&$error) {
            $error = $message;
        });

        $parser->feed("GET / HTTP/1.2\r\n\r\n");

        $this->assertInstanceOf('InvalidArgumentException', $error);
        $this->assertSame(505, $error->getCode());
        $this->assertSame('Received request with invalid protocol version', $error->getMessage());
    }

    private function createGetRequest()
    {
        $data = "GET / HTTP/1.1\r\n";
        $data .= "Host: example.com:80\r\n";
        $data .= "Connection: close\r\n";
        $data .= "\r\n";

        return $data;
    }

    private function createAdvancedPostRequest()
    {
        $data = "POST /foo?bar=baz HTTP/1.1\r\n";
        $data .= "Host: example.com:80\r\n";
        $data .= "User-Agent: react/alpha\r\n";
        $data .= "Connection: close\r\n";
        $data .= "\r\n";

        return $data;
    }
}
