<?php

namespace DDTrace\Integrations\Curl;

use DDTrace\Configuration;
use DDTrace\Format;
use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use DDTrace\Span;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ArrayKVStore;
use DDTrace\GlobalTracer;

/**
 * Integration for curl php client.
 */
class CurlIntegration extends Integration
{
    const NAME = 'curl';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Loads the integration.
     */
    public static function load()
    {
        if (!extension_loaded('curl')) {
            // `curl` extension is not loaded, if it does not exists we can return this integration as
            // not available.
            return Integration::NOT_AVAILABLE;
        }

        // Waiting for refactoring from static to singleton.
        $integration = new self();
        $globalConfig = Configuration::get();

        dd_trace('curl_exec', function ($ch) use ($integration, $globalConfig) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                CurlIntegration::injectDistributedTracingHeaders($ch);

                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'curl_exec');
            $span = $scope->getSpan();
            $span->setTraceAnalyticsCandidate();
            $span->setTag(Tag::SPAN_TYPE, Type::HTTP_CLIENT);
            CurlIntegration::injectDistributedTracingHeaders($ch);

            $result = dd_trace_forward_call();
            if ($result === false && $span instanceof Span) {
                $span->setRawError(curl_error($ch), 'curl error');
            }

            $info = curl_getinfo($ch);
            $sanitizedUrl = Urls::sanitize($info['url']);
            if ($globalConfig->isHttpClientSplitByDomain()) {
                $span->setTag(Tag::SERVICE_NAME, Urls::hostnameForTag($sanitizedUrl));
            }
            $httpMethod = ArrayKVStore::getForResource($ch, "HTTP_METHOD", "GET");
            $span->setTag(Tag::HTTP_METHOD, $httpMethod);
            $span->setTag(Tag::COMPONENT, 'curl');
            $span->setTag(Tag::RESOURCE_NAME, $sanitizedUrl);
            $span->setTag(Tag::HTTP_URL, $sanitizedUrl);
            $span->setTag(Tag::HTTP_STATUS_CODE, $info['http_code']);

            $scope->close();
            return $result;
        });

        dd_trace('curl_setopt', function ($ch, $option, $value) use ($globalConfig) {
            // Note that curl_setopt with option CURLOPT_HTTPHEADER overwrite data instead of appending it if called
            // multiple times on the same resource.
            if ($option === CURLOPT_HTTPHEADER
                    && $globalConfig->isDistributedTracingEnabled()
                    && is_array($value)
            ) {
                // Storing data to be used during exec as it cannot be retrieved at then.
                ArrayKVStore::putForResource($ch, Format::CURL_HTTP_HEADERS, $value);
            }

            switch ($option) {
                case CURLOPT_CUSTOMREQUEST:
                    ArrayKVStore::putForResource($ch, "HTTP_METHOD", $value);
                    ArrayKVStore::putForResource($ch, "CUSTOMREQUEST_SET", true);
                    break;
                case CURLOPT_PUT:
                    if ($value && !(ArrayKVStore::getForResource($ch, "CUSTOMREQUEST_SET", false))) {
                        ArrayKVStore::putForResource($ch, "HTTP_METHOD", "PUT");
                    }
                    break;
                case CURLOPT_POST:
                    if ($value && !(ArrayKVStore::getForResource($ch, "CUSTOMREQUEST_SET", false))) {
                        ArrayKVStore::putForResource($ch, "HTTP_METHOD", "POST");
                    }
                    break;
                case CURLOPT_HTTPGET:
                    if ($value && !(ArrayKVStore::getForResource($ch, "CUSTOMREQUEST_SET", false))) {
                        ArrayKVStore::putForResource($ch, "HTTP_METHOD", "GET");
                    }
                    break;
                case CURLOPT_NOBODY:
                    if ($value && !(ArrayKVStore::getForResource($ch, "CUSTOMREQUEST_SET", false))) {
                        ArrayKVStore::putForResource($ch, "HTTP_METHOD", "HEAD");
                        break;
                    }
            }

            return dd_trace_forward_call();
        });

        dd_trace('curl_setopt_array', function ($ch, $options) use ($globalConfig) {
            // Note that curl_setopt with option CURLOPT_HTTPHEADER overwrite data instead of appending it if called
            // multiple times on the same resource.
            if ($globalConfig->isDistributedTracingEnabled()
                    && array_key_exists(CURLOPT_HTTPHEADER, $options)
            ) {
                // Storing data to be used during exec as it cannot be retrieved at then.
                ArrayKVStore::putForResource($ch, Format::CURL_HTTP_HEADERS, $options[CURLOPT_HTTPHEADER]);
            }

            if (array_key_exists(CURLOPT_CUSTOMREQUEST, $options)) {
                ArrayKVStore::putForResource($ch, "CUSTOMREQUEST_SET", true);
                ArrayKVStore::putForResource($ch, "HTTP_METHOD", $options[CURLOPT_CUSTOMREQUEST]);
            } elseif (array_key_exists(CURLOPT_PUT, $options)
                    && $options[CURLOPT_PUT]
                    && !(ArrayKVStore::getForResource($ch, "CUSTOMREQUEST_SET", false))) {
                ArrayKVStore::putForResource($ch, "HTTP_METHOD", "PUT");
            } elseif (array_key_exists(CURLOPT_POST, $options)
                    && $options[CURLOPT_POST]
                    && !(ArrayKVStore::getForResource($ch, "CUSTOMREQUEST_SET", false))) {
                ArrayKVStore::putForResource($ch, "HTTP_METHOD", "POST");
            } elseif (array_key_exists(CURLOPT_HTTPGET, $options)
                    && $options[CURLOPT_HTTPGET]
                    && !(ArrayKVStore::getForResource($ch, "CUSTOMREQUEST_SET", false))) {
                ArrayKVStore::putForResource($ch, "HTTP_METHOD", "GET");
            } elseif (array_key_exists(CURLOPT_NOBODY, $options)
                    && $options[CURLOPT_NOBODY]
                    && !(ArrayKVStore::getForResource($ch, "CUSTOMREQUEST_SET", false))) {
                ArrayKVStore::putForResource($ch, "HTTP_METHOD", "HEAD");
            }
            return dd_trace_forward_call();
        });

        dd_trace('curl_close', function ($ch) use ($globalConfig) {
            ArrayKVStore::deleteResource($ch);
            return dd_trace_forward_call();
        });

        return Integration::LOADED;
    }

    /**
     * @param resource $ch
     */
    public static function injectDistributedTracingHeaders($ch)
    {
        if (!Configuration::get()->isDistributedTracingEnabled()) {
            return;
        }

        $httpHeaders = ArrayKVStore::getForResource($ch, Format::CURL_HTTP_HEADERS, []);
        if (is_array($httpHeaders)) {
            $tracer = GlobalTracer::get();
            $activeSpan = $tracer->getActiveSpan();
            if ($activeSpan !== null) {
                $context = $activeSpan->getContext();
                $tracer->inject($context, Format::CURL_HTTP_HEADERS, $httpHeaders);

                curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
            }
        }
    }
}
