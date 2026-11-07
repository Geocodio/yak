import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:23
* @route '/queue-metrics/workers/status'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/workers/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:23
* @route '/queue-metrics/workers/status'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:23
* @route '/queue-metrics/workers/status'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::index
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:23
* @route '/queue-metrics/workers/status'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:57
* @route '/queue-metrics/workers/status/{workerId}'
*/
export const show = (args: { workerId: string | number } | [workerId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/workers/status/{workerId}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:57
* @route '/queue-metrics/workers/status/{workerId}'
*/
show.url = (args: { workerId: string | number } | [workerId: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { workerId: args }
    }

    if (Array.isArray(args)) {
        args = {
            workerId: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        workerId: args.workerId,
    }

    return show.definition.url
            .replace('{workerId}', parsedArgs.workerId.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:57
* @route '/queue-metrics/workers/status/{workerId}'
*/
show.get = (args: { workerId: string | number } | [workerId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:57
* @route '/queue-metrics/workers/status/{workerId}'
*/
show.head = (args: { workerId: string | number } | [workerId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::detectStale
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:91
* @route '/queue-metrics/workers/detect-stale'
*/
export const detectStale = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: detectStale.url(options),
    method: 'post',
})

detectStale.definition = {
    methods: ["post"],
    url: '/queue-metrics/workers/detect-stale',
} satisfies RouteDefinition<["post"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::detectStale
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:91
* @route '/queue-metrics/workers/detect-stale'
*/
detectStale.url = (options?: RouteQueryOptions) => {
    return detectStale.definition.url + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\WorkerStatusController::detectStale
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/WorkerStatusController.php:91
* @route '/queue-metrics/workers/detect-stale'
*/
detectStale.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: detectStale.url(options),
    method: 'post',
})

const WorkerStatusController = { index, show, detectStale }

export default WorkerStatusController