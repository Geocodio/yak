import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllQueuesController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllQueuesController.php:20
* @route '/queue-metrics/queues/all'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/queues/all',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllQueuesController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllQueuesController.php:20
* @route '/queue-metrics/queues/all'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllQueuesController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllQueuesController.php:20
* @route '/queue-metrics/queues/all'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllQueuesController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllQueuesController.php:20
* @route '/queue-metrics/queues/all'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const AllQueuesController = { index }

export default AllQueuesController