import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
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

const QueueMetricsController = { show }

export default QueueMetricsController