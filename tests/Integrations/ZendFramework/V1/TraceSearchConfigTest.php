<?php

namespace DDTrace\Tests\Integrations\ZendFramework\V1;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class TraceSearchConfigTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/ZendFramework/Version_1_12/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_ANALYTICS_ENABLED' => 'true',
            'DD_ZENDFRAMEWORK_ANALYTICS_SAMPLE_RATE' => '0.3',
        ]);
    }

    /**
     * @throws \Exception
     */
    public function testScenario()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('Testing trace analytics config metric', '/simple'));
        });

        $this->assertExpectedSpans(
            $this,
            $traces,
            [
                SpanAssertion::build(
                    'zf1.request',
                    'unnamed-php-service',
                    SpanAssertion::NOT_TESTED,
                    'simple@index default'
                )->withExactTags([
                    'zf1.controller' => 'simple',
                    'zf1.action' => 'index',
                    'zf1.route_name' => 'default',
                    'http.method' => 'GET',
                    'http.url' => 'http://localhost:9999/simple',
                    'http.status_code' => '200',
                    'integration.name' => 'zendframework',
                    'component' => 'zendframework',
                ])
                ->withExactMetrics([
                    '_dd1.sr.eausr' => 0.3,
                    '_sampling_priority_v1' => 1,
                ]),
            ]
        );
    }
}
