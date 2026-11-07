import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllServersController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllServersController.php:20
* @route '/queue-metrics/servers'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/servers',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllServersController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllServersController.php:20
* @route '/queue-metrics/servers'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllServersController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllServersController.php:20
* @route '/queue-metrics/servers'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\AllServersController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/AllServersController.php:20
* @route '/queue-metrics/servers'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const AllServersController = { index }

export default AllServersController