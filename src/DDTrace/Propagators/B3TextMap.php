<?php

namespace DDTrace\Propagators;

use DDTrace\Propagator;
use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Contracts\Tracer;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\SpanContext;


/*
 * B3 propagation protocol
 */

final class B3TextMap implements Propagator
{
    /**
     * @var Tracer
     */
    private $tracer;

    /**
     * @param Tracer $tracer
     */
    public function __construct(Tracer $tracer)
    {
        $this->tracer = $tracer;
    }

    /**
     * {@inheritdoc}
     */
    public function inject(SpanContextInterface $spanContext, &$carrier)
    {
        $carrier[B3_TRACE_ID_HEADER] = $spanContext->getTraceId();
        $carrier[B3_SPAN_ID_HEADER] = $spanContext->getSpanId();
        if ($spanContext->getParentId() !== null) {
            $carrier[B3_PARENT_SPAN_ID_HEADER] = $spanContext->getParentId();
        }

        foreach ($spanContext as $key => $value) {
            $carrier[B3_BAGGAGE_HEADER_PREFIX . $key] = $value;
        }

        $prioritySampling = $this->tracer->getPrioritySampling();
        if ($prioritySampling === PrioritySampling::USER_KEEP) {
            $carrier[B3_FLAGS_HEADER] = "1";
        } elseif ($prioritySampling === PrioritySampling::AUTO_KEEP) {
            $carrier[B3_SAMPLED_HEADER] = "1";
        } else {
            $carrier[B3_SAMPLED_HEADER] = "0";
        }
    }

    /**
     * {@inheritdoc}
     */
    public function extract($carrier)
    {
        $traceId = null;
        $parentSpanId = null;
        $spanId = null;
        $sampled = null;
        $flags = null;
        $baggageItems = [];

        foreach ($carrier as $key => $value) {
            if ($key === B3_TRACE_ID_HEADER) {
                $traceId = (string) $this->extractStringOrFirstArrayElement($value);
            } elseif ($key === B3_SPAN_ID_HEADER) {
                $spanId = (string) $this->extractStringOrFirstArrayElement($value);
            } elseif ($key === B3_PARENT_SPAN_ID_HEADER) {
                $parentSpanId = (string) $this->extractStringOrFirstArrayElement($value);
            } elseif ($key === B3_SAMPLED_HEADER) {
                $sampled = $this->extractStringOrFirstArrayElement($value);
            } elseif ($key === B3_FLAGS_HEADER) {
                $flags = $this->extractStringOrFirstArrayElement($value);
            } elseif (strpos($key, B3_BAGGAGE_HEADER_PREFIX) === 0) {
                $baggageItems[substr($key, strlen(B3_BAGGAGE_HEADER_PREFIX))] = $value;
            }
        }

        if ($traceId === null || $spanId === null) {
            return null;
        }

        $spanContext = new SpanContext($traceId, $spanId, $parentSpanId, $baggageItems, true);

        $prioritySampling = null;
        if ($sampled === "0") {
            $prioritySampling = PrioritySampling::AUTO_REJECT;
        } elseif ($sampled === "1") {
            $prioritySampling = PrioritySampling::AUTO_KEEP;
        } elseif ($flags === "1") {
            $prioritySampling = PrioritySampling::USER_KEEP;
        }
        $spanContext->setPropagatedPrioritySampling($prioritySampling);

        return $spanContext;
    }

    /**
     * A utility function to mitigate differences between how headers are provided by various web frameworks.
     * E.g. in both the cases that follow, this method would return 'application/json':
     *   1) as array of values: ['content-type' => ['application/json']]
     *   2) as string value: ['content-type' => 'application/json']
     *   3) as the last part of string from a comma or semicolor separated string
     *
     * @param array|string $value
     * @return string|null
     */
    private function extractStringOrFirstArrayElement($value)
    {
        if (is_array($value) && count($value) > 0) {
            return $value[0];
        } elseif (is_string($value)) {
            $split = explode(",", $value);
            $value = end($split);
            $split = explode(";", $value);
            return end($split);
        }
        return null;
    }
}
