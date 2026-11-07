import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
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
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:22
* @route '/queue-metrics/queues/{connection}/{queue}/depth'
*/
export const show = (args: { connection: string | number, queue: string | number } | [connection: string | number, queue: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/queues/{connection}/{queue}/depth',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:22
* @route '/queue-metrics/queues/{connection}/{queue}/depth'
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
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:22
* @route '/queue-metrics/queues/{connection}/{queue}/depth'
*/
show.get = (args: { connection: string | number, queue: string | number } | [connection: string | number, queue: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\QueueDepthController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/QueueDepthController.php:22
* @route '/queue-metrics/queues/{connection}/{queue}/depth'
*/
show.head = (args: { connection: string | number, queue: string | number } | [connection: string | number, queue: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const QueueDepthController = { index, show }

export default QueueDepthController