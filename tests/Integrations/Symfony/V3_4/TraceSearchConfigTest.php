<?php

namespace DDTrace\Tests\Integrations\Symfony\V3_4;

use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class TraceSearchConfigTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/../../../Frameworks/Symfony/Version_3_4/web/app.php';
    }

    protected static function getEnvs()
    {
        return array_merge(parent::getEnvs(), [
            'DD_TRACE_ANALYTICS_ENABLED' => 'true',
            'DD_SYMFONY_ANALYTICS_SAMPLE_RATE' => '0.3',
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
                    'symfony.request',
                    'unnamed-php-service',
                    SpanAssertion::NOT_TESTED,
                    'simple'
                )
                    ->withExactTags([
                        'symfony.route.action' => 'AppBundle\Controller\CommonScenariosController@simpleAction',
                        'symfony.route.name' => 'simple',
                        'http.method' => 'GET',
                        'http.url' => 'http://localhost:9999/simple',
                        'http.status_code' => '200',
                        'integration.name' => 'symfony',
                        'component' => 'symfony'
                    ])
                    ->withExactMetrics([
                        '_dd1.sr.eausr' => 0.3,
                        '_sampling_priority_v1' => 1,
                    ]),
                SpanAssertion::exists('symfony.kernel.handle'),
                SpanAssertion::exists('symfony.kernel.request'),
                SpanAssertion::exists('symfony.kernel.controller'),
                SpanAssertion::exists('symfony.kernel.controller_arguments'),
                SpanAssertion::exists('symfony.kernel.response'),
                SpanAssertion::exists('symfony.kernel.finish_request'),
                SpanAssertion::exists('symfony.kernel.terminate'),
            ]
        );
    }
}
