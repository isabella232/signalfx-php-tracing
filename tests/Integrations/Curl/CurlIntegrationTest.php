<?php

namespace DDTrace\Tests\Integrations\Curl;

use DDTrace\Configuration;
use DDTrace\Format;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tests\Common\IntegrationTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tracer;
use DDTrace\Util\ArrayKVStore;
use DDTrace\GlobalTracer;
use DDTrace\Util\HexConversion;
use DDTrace\Util\Versions;

class PrivateCallbackRequest
{
    private static function parseResponseHeaders($ch, $headers)
    {
        return strlen($headers);
    }

    public function request()
    {
        $ch = curl_init(CurlIntegrationTest::URL . '/status/200');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, __CLASS__ . '::parseResponseHeaders');
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}

final class CurlIntegrationTest extends IntegrationTestCase
{
    const URL = 'http://httpbin_integration';
    const URL_NOT_EXISTS = 'http://__i_am_not_real__.invalid/';

    public function setUp()
    {
        parent::setUp();
        putenv('DD_CURL_ANALYTICS_ENABLED=true');
        IntegrationsLoader::load();
    }

    public function tearDown()
    {
        parent::tearDown();
        putenv('DD_CURL_ANALYTICS_ENABLED');
    }

    public function testLoad200UrlOnInit()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'cli', 'http', 'http://httpbin_integration/status/200')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                    'component' => 'curl',
                    'http.method' => 'GET',
                ]),
        ]);
    }

    public function testSampleExternalAgent()
    {
        putenv('DD_CURL_ANALYTICS_ENABLED');
        Configuration::clear();
        $traces = $this->simulateAgent(function () {
            $ch = curl_init(self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'curl_exec',
                'unnamed-php-service',
                SpanAssertion::NOT_TESTED,
                'http://httpbin_integration/status/200'
            )->withExactTags([
                'http.url' => self::URL . '/status/200',
                'http.status_code' => '200',
                'http.method' => 'GET',
                'component' => 'curl',
            ]),
        ]);
    }

    public function testLoad200UrlAsOpt()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'cli', 'http', 'http://httpbin_integration/status/200')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                    'component' => 'curl',
                    'http.method' => 'GET',
                ]),
        ]);
    }

    public function testPrivateCallbackForResponseHeaders()
    {
        $traces = $this->isolateTracer(function () {
            $foo = new PrivateCallbackRequest();
            $response = $foo->request();
            $this->assertEmpty($response);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'cli', 'http', 'http://httpbin_integration/status/200')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                    'component' => 'curl',
                    'http.method' => 'GET',
                ]),
        ]);
    }

    public function testLoad404UrlOnInit()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL . '/status/404');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'cli', 'http', 'http://httpbin_integration/status/404')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/404',
                    'http.status_code' => '404',
                    'component' => 'curl',
                    'http.method' => 'GET',
                ]),
        ]);
    }

    public function testHttpMethodPost()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, 1);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'cli', 'http', 'http://httpbin_integration/status/200')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                    'component' => 'curl',
                    'http.method' => 'POST',
                ]),
        ]);
    }

    public function testHttpMethodCustomRequest()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'cli', 'http', 'http://httpbin_integration/status/200')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                    'component' => 'curl',
                    'http.method' => 'DELETE',
                ]),
        ]);
    }

    public function testLoadUnroutableIP()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "http://10.255.255.1/");
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 100);
            curl_exec($ch);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'cli', 'http', 'http://10.255.255.1/')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => 'http://10.255.255.1/',
                    'http.status_code' => '0',
                    'component' => 'curl',
                    'http.method' => 'GET',
                ])
                ->withExistingTagsNames(['sfx.error.message'])
                ->setError('curl error'),
        ]);
    }

    public function testLoadOperationTimeout()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "http://10.255.255.1/");
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
            curl_exec($ch);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'cli', 'http', 'http://10.255.255.1/')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => 'http://10.255.255.1/',
                    'http.status_code' => '0',
                    'component' => 'curl',
                    'http.method' => 'GET',
                ])
                ->withExistingTagsNames(['sfx.error.message'])
                ->setError('curl error'),
        ]);
    }

    public function testNonExistingHost()
    {
        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL_NOT_EXISTS);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertFalse($response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build('curl_exec', 'cli', 'http', 'http://__i_am_not_real__.invalid/')
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => 'http://__i_am_not_real__.invalid/',
                    'http.status_code' => '0',
                    'component' => 'curl',
                    'http.method' => 'GET',
                ])
                ->setError('curl error', 'Could not resolve host: __i_am_not_real__.invalid'),
        ]);
    }

    public function testKVStoreIsCleanedOnCurlClose()
    {
        $ch = curl_init(self::URL . '/status/200');
        curl_setopt($ch, CURLOPT_HTTPHEADER, []);
        $this->assertNotSame('default', ArrayKVStore::getForResource($ch, Format::CURL_HTTP_HEADERS, 'default'));
        curl_close($ch);
        $this->assertSame('default', ArrayKVStore::getForResource($ch, Format::CURL_HTTP_HEADERS, 'default'));
    }

    public function testDistributedTracingIsPropagated()
    {
        $found = [];
        $traces = $this->isolateTracer(function () use (&$found) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $tracer->setPrioritySampling(PrioritySampling::AUTO_KEEP);
            $span = $tracer->startActiveSpan('custom')->getSpan();

            $ch = curl_init(self::URL . '/headers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'honored: preserved_value',
            ]);
            $found = json_decode(curl_exec($ch), 1);

            $span->finish();
        });

        // trace is: some_operation
        $this->assertSame(
            $traces[0][0]['span_id'],
            $found['headers']['X-B3-Traceid']
        );
        // parent is: curl_exec
        $this->assertSame(
            $traces[0][1]['span_id'],
            $found['headers']['X-B3-Spanid']
        );
        // existing headers are honored
        $this->assertSame('preserved_value', $found['headers']['Honored']);
    }

    public function testDistributedTracingIsNotPropagatedIfDisabled()
    {
        $found = [];
        Configuration::replace(\Mockery::mock(Configuration::get(), [
            'isAutofinishSpansEnabled' => false,
            'isAnalyticsEnabled' => false,
            'isDistributedTracingEnabled' => false,
            'isPrioritySamplingEnabled' => false,
            'getGlobalTags' => [],
            'getSpansLimit' => -1,
            'isDebugModeEnabled' => false,
        ]));

        $this->isolateTracer(function () use (&$found) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $tracer->setPrioritySampling(PrioritySampling::AUTO_KEEP);
            $span = $tracer->startActiveSpan('custom')->getSpan();

            $ch = curl_init(self::URL . '/headers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $found = json_decode(curl_exec($ch), 1);
            $span->finish();
        });

        $this->assertArrayNotHasKey('X-B3-Traceid', $found['headers']);
        $this->assertArrayNotHasKey('X-B3-Spanid', $found['headers']);
    }

    public function testTracerIsRunningAtLimitedCapacityWeStillPropagateTheSpan()
    {
        $found = [];
        Configuration::replace(\Mockery::mock(Configuration::get(), [
            'getSpansLimit' => 0
        ]));

        $traces = $this->isolateTracer(function () use (&$found) {
            /** @var Tracer $tracer */
            $tracer = GlobalTracer::get();
            $tracer->setPrioritySampling(PrioritySampling::AUTO_KEEP);
            $span = $tracer->startActiveSpan('custom')->getSpan();

            $ch = curl_init(self::URL . '/headers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'honored: preserved_value',
            ]);
            $found = json_decode(curl_exec($ch), 1);

            $span->finish();
        });

        // existing headers are honored
        $this->assertSame('preserved_value', $found['headers']['Honored']);

        $this->assertEquals(1, sizeof($traces[0]));

        // trace is: custom
        $this->assertSame($traces[0][0]['trace_id'], $found['headers']['X-B3-Traceid']);
        // parent is: custom
        $this->assertSame($traces[0][0]['span_id'], $found['headers']['X-B3-Spanid']);
    }

    public function testTracerRunningAtLimitedCapacityCurlWorksWithoutARootSpan()
    {
        $found = [];
        $traces = $this->isolateLimitedTracer(function () use (&$found) {
            /** @var Tracer $tracer */
            $ch = curl_init(self::URL . '/headers');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'honored: preserved_value',
            ]);
            $found = json_decode(curl_exec($ch), 1);
        });

        // existing headers are honored
        $this->assertSame('preserved_value', $found['headers']['Honored']);

        $this->assertArrayNotHasKey('X-B3-Traceid', $found['headers']);
        $this->assertArrayNotHasKey('X-B3-Spanid', $found['headers']);

        $this->assertEmpty($traces);
    }

    public function testAppendHostnameToServiceName()
    {
        putenv('SIGNALFX_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN=true');

        $traces = $this->isolateTracer(function () {
            $ch = curl_init(self::URL . '/status/200');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $this->assertSame('', $response);
            curl_close($ch);
        });

        $this->assertSpans($traces, [
            SpanAssertion::build(
                'curl_exec',
                'host-httpbin_integration',
                'http',
                'http://httpbin_integration/status/200'
            )
                ->setTraceAnalyticsCandidate()
                ->withExactTags([
                    'http.url' => self::URL . '/status/200',
                    'http.status_code' => '200',
                    'component' => 'curl',
                    'http.method' => 'GET',
                ]),
        ]);
    }
}
