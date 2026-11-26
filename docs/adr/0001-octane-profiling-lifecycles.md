# Octane profiling lifecycles

Accepted

The PHP sampler observes execution in a worker process. RoadRunner runs HTTP
requests sequentially. Swoole and OpenSwoole can interleave coroutines in the
same HTTP worker. Attributing a continuous process sample to a particular
request would produce misleading route labels.

Octane profiling is opt-in. The bridge registers a subscriber at application
boot. Its listeners stay bound to the same subscriber instance across request
sandboxes. The subscriber starts the corresponding core profiler after
confirming the actual server at worker startup.

RoadRunner uses one unit-of-work profiler per worker. It starts on
`RequestReceived` and reads the route name on `RequestTerminated`. If normal
termination is interrupted, `OperationReleased` closes the active unit of work.
The RoadRunner subscriber uses the same HTTP labeling and request ownership
as the regular Laravel kernel.

Swoole and OpenSwoole use one continuous profiler with static labels per HTTP
worker. `OperationReleased` pulses it at most once per second outside the
Excimer callback. A periodic native timer can delay worker reload while the
event loop drains, so idle delivery waits for the next completed request or
graceful exit. The core controls the aggregation window and spool writes.
`WorkerStopping` drains the remaining buckets on graceful exit. Task workers
are excluded because they have a separate lifecycle.
