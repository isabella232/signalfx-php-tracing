<?php

namespace DDTrace\Integrations;

use DDTrace\Configuration;
use DDTrace\Integrations\CakePHP\CakePHPIntegration;
use DDTrace\Integrations\Curl\CurlIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Integrations\Eloquent\EloquentIntegration;
use DDTrace\Integrations\Guzzle\GuzzleIntegration;
use DDTrace\Integrations\Laravel\LaravelIntegration;
use DDTrace\Integrations\Lumen\LumenIntegration;
use DDTrace\Integrations\Memcached\MemcachedIntegration;
use DDTrace\Integrations\Mongo\MongoIntegration;
use DDTrace\Integrations\Mysqli\MysqliIntegration;
use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Integrations\Predis\PredisIntegration;
use DDTrace\Integrations\Slim\SlimIntegration;
use DDTrace\Integrations\Symfony\SymfonyIntegration;
use DDTrace\Integrations\Web\WebIntegration;
use DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration;
use DDTrace\Log\LoggingTrait;

/**
 * Loader for all integrations currently enabled.
 */
class IntegrationsLoader
{
    use LoggingTrait;

    /**
     * @var IntegrationsLoader
     */
    private static $instance;

    /**
     * @var array
     */
    private $integrations = [];

    /**
     * @var array
     */
    public static $officiallySupportedIntegrations = [
        CakePHPIntegration::NAME => '\DDTrace\Integrations\CakePHP\CakePHPIntegration',
        CurlIntegration::NAME => '\DDTrace\Integrations\Curl\CurlIntegration',
        ElasticSearchIntegration::NAME => '\DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration',
        EloquentIntegration::NAME => '\DDTrace\Integrations\Eloquent\EloquentIntegration',
        GuzzleIntegration::NAME => '\DDTrace\Integrations\Guzzle\GuzzleIntegration',
        LaravelIntegration::NAME => '\DDTrace\Integrations\Laravel\LaravelIntegration',
        LumenIntegration::NAME => '\DDTrace\Integrations\Lumen\LumenIntegration',
        MemcachedIntegration::NAME => '\DDTrace\Integrations\Memcached\MemcachedIntegration',
        MongoIntegration::NAME => '\DDTrace\Integrations\Mongo\MongoIntegration',
        MysqliIntegration::NAME => '\DDTrace\Integrations\Mysqli\MysqliIntegration',
        PDOIntegration::NAME => '\DDTrace\Integrations\PDO\PDOIntegration',
        PredisIntegration::NAME => '\DDTrace\Integrations\Predis\PredisIntegration',
        SlimIntegration::NAME => '\DDTrace\Integrations\Slim\SlimIntegration',
        SymfonyIntegration::NAME => '\DDTrace\Integrations\Symfony\SymfonyIntegration',
        WebIntegration::NAME => '\DDTrace\Integrations\Web\WebIntegration',
        ZendFrameworkIntegration::NAME => '\DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration',
    ];

    /**
     * @var array Registry to keep track of integrations loading status.
     */
    private $loadings = [];

    /**
     * @param array|null $integrations
     */
    public function __construct(array $integrations)
    {
        $this->integrations = $integrations;
    }

    /**
     * Returns the singleton integration loader.
     *
     * @return IntegrationsLoader
     */
    public static function get()
    {
        if (null === self::$instance) {
            self::$instance = new IntegrationsLoader(self::$officiallySupportedIntegrations);
        }

        return self::$instance;
    }

    /**
     * Loads all the integrations registered with this loader instance.
     */
    public function loadAll()
    {
        $globalConfig = Configuration::get();
        if (!$globalConfig->isEnabled()) {
            return;
        }

        if (!extension_loaded('signalfx_tracing')) {
            trigger_error(
                'Missing signalfx_tracing extension. To disable tracing set env variable '
                . 'SIGNALFX_TRACING_ENABLED=false',
                E_USER_WARNING
            );
            return;
        }

        self::logDebug('Attempting integrations load');

        foreach ($this->integrations as $name => $class) {
            if (!$globalConfig->isIntegrationEnabled($name)) {
                self::logDebug('Integration {name} is disabled', ['name' => $name]);
                continue;
            }

            // If the integration has already been loaded, we don't need to reload it. On the other hand, with
            // auto-instrumentation this method may be called many times as the hook is the autoloader callback.
            // So we want to make sure that we do not load the same integration twice if not required.
            $integrationLoadingStatus = $this->getLoadingStatus($name);
            if (in_array($integrationLoadingStatus, [Integration::LOADED, Integration::NOT_AVAILABLE])) {
                continue;
            }

            $this->loadings[$name] = call_user_func([$class, 'load']);
            $this->logResult($name, $this->loadings[$name]);
        }
    }

    /**
     * Logs a proper message to report the status of an integration loading attempt.
     *
     * @param string $name
     * @param int $result
     */
    private function logResult($name, $result)
    {
        if ($result === Integration::LOADED) {
            self::logDebug('Loaded integration {name}', ['name' => $name]);
        } elseif ($result === Integration::NOT_AVAILABLE) {
            self::logDebug('Integration {name} not available. New attempts WILL NOT be performed.', [
                'name' => $name,
            ]);
        } elseif ($result === Integration::NOT_LOADED) {
            self::logDebug('Integration {name} not loaded. New attempts might be performed.', [
                'name' => $name,
            ]);
        } else {
            self::logError('Invalid value returning by integration loader for {name}: {value}', [
                'name' => $name,
                'value' => $result,
            ]);
        }
    }

    /**
     * Returns the registered integrations.
     *
     * @return array
     */
    public function getIntegrations()
    {
        return $this->integrations;
    }

    /**
     * Returns the provide integration current loading status.
     *
     * @param string $integrationName
     * @return int
     */
    public function getLoadingStatus($integrationName)
    {
        return isset($this->loadings[$integrationName]) ? $this->loadings[$integrationName] : Integration::NOT_LOADED;
    }

    /**
     * Loads all the enabled library integrations using the global singleton integrations loader which in charge
     * only of the officially supported integrations.
     */
    public static function load()
    {
        self::get()->loadAll();
    }

    public function reset()
    {
        $this->integrations = [];
    }
}
