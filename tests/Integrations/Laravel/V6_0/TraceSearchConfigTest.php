<?php

namespace DDTrace\Tests\Integrations\Laravel\V6_0;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class TraceSearchConfigTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Laravel/Version_6_0/public/index.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_ANALYTICS_ENABLED' => 'true',
            'DD_LARAVEL_ANALYTICS_SAMPLE_RATE' => '0.3',
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
                    'App\Http\Controllers\CommonSpecsController@simple simple_route',
                    'unnamed-php-service',
                    SpanAssertion::NOT_TESTED,
                    SpanAssertion::NOT_TESTED
                )
                    ->withExactTags([
                        'laravel.route.name' => 'simple_route',
                        'laravel.route.action' => 'App\Http\Controllers\CommonSpecsController@simple',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/simple',
                        'http.status_code' => '200',
                        'integration.name' => 'laravel',
                        'component' => 'laravel'
                    ])
                    ->withExactMetrics([
                        '_dd1.sr.eausr' => 0.3,
                        '_sampling_priority_v1' => 1,
                    ]),
            ]
        );
    }
}
