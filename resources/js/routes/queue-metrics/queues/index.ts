import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllQueuesController::all
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllQueuesController.php:20
* @route '/queue-metrics/queues/all'
*/
export const all = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: all.url(options),
    method: 'get',
})

all.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/queues/all',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllQueuesController::all
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllQueuesController.php:20
* @route '/queue-metrics/queues/all'
*/
all.url = (options?: RouteQueryOptions) => {
    return all.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllQueuesController::all
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllQueuesController.php:20
* @route '/queue-metrics/queues/all'
*/
all.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: all.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllQueuesController::all
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllQueuesController.php:20
* @route '/queue-metrics/queues/all'
*/
all.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: all.url(options),
    method: 'head',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueMetricsController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueMetricsController.php:20
* @route '/queue-metrics/queues/{connection}/{queue}'
*/
export const show = (args: { connection: string | number, queue: string | number } | [connection: string | number, queue: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/queues/{connection}/{queue}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueMetricsController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueMetricsController.php:20
* @route '/queue-metrics/queues/{connection}/{queue}'
*/
show.url = (args: { connection: string | number, queue: string | number } | [connection: string | number, queue: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            connection: args[0],
            queue: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        connection: args.connection,
        queue: args.queue,
    }

    return show.definition.url
            .replace('{connection}', parsedArgs.connection.toString())
            .replace('{queue}', parsedArgs.queue.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueMetricsController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueMetricsController.php:20
* @route '/queue-metrics/queues/{connection}/{queue}'
*/
show.get = (args: { connection: string | number, queue: string | number } | [connection: string | number, queue: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueMetricsController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueMetricsController.php:20
* @route '/queue-metrics/queues/{connection}/{queue}'
*/
show.head = (args: { connection: string | number, queue: string | number } | [connection: string | number, queue: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:34
* @route '/queue-metrics/queues'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/queues',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:34
* @route '/queue-metrics/queues'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:34
* @route '/queue-metrics/queues'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:34
* @route '/queue-metrics/queues'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::depth
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:22
* @route '/queue-metrics/queues/{connection}/{queue}/depth'
*/
export const depth = (args: { connection: string | number, queue: string | number } | [connection: string | number, queue: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: depth.url(args, options),
    method: 'get',
})

depth.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/queues/{connection}/{queue}/depth',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::depth
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:22
* @route '/queue-metrics/queues/{connection}/{queue}/depth'
*/
depth.url = (args: { connection: string | number, queue: string | number } | [connection: string | number, queue: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            connection: args[0],
            queue: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        connection: args.connection,
        queue: args.queue,
    }

    return depth.definition.url
            .replace('{connection}', parsedArgs.connection.toString())
            .replace('{queue}', parsedArgs.queue.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::depth
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:22
* @route '/queue-metrics/queues/{connection}/{queue}/depth'
*/
depth.get = (args: { connection: string | number, queue: string | number } | [connection: string | number, queue: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: depth.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::depth
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:22
* @route '/queue-metrics/queues/{connection}/{queue}/depth'
*/
depth.head = (args: { connection: string | number, queue: string | number } | [connection: string | number, queue: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: depth.url(args, options),
    method: 'head',
})

const queues = {
    all: Object.assign(all, all),
    show: Object.assign(show, show),
    index: Object.assign(index, index),
    depth: Object.assign(depth, depth),
}

export default queues