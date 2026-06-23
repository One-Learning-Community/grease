<?php

namespace Grease\Tests\Fixtures\Container;

use Illuminate\Http\Request;

/**
 * Exercises both container dependency-resolution entry points in one request:
 * constructor injection (resolved when the controller is built) and method injection
 * (resolved at dispatch). Returns a deterministic array so the served JSON is a stable
 * parity oracle.
 */
class SpikeController
{
    public function __construct(private SpikeService $service)
    {
    }

    public function show(Request $request, SpikeService $injected): array
    {
        return [
            'ctor' => $this->service->greeting(),
            'method' => $injected->greeting(),
            'q' => $request->query('q'),
            // Transient (unbound) service → two distinct instances, in vanilla and greased alike.
            'same_instance' => $this->service === $injected,
        ];
    }
}
