import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\JobMetricsController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/JobMetricsController.php:20
* @route '/queue-metrics/jobs/{jobClass}'
*/
export const show = (args: { jobClass: string | number } | [jobClass: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/queue-metrics/jobs/{jobClass}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\JobMetricsController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/JobMetricsController.php:20
* @route '/queue-metrics/jobs/{jobClass}'
*/
show.url = (args: { jobClass: string | number } | [jobClass: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { jobClass: args }
    }

    if (Array.isArray(args)) {
        args = {
            jobClass: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        jobClass: args.jobClass,
    }

    return show.definition.url
            .replace('{jobClass}', parsedArgs.jobClass.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\JobMetricsController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/JobMetricsController.php:20
* @route '/queue-metrics/jobs/{jobClass}'
*/
show.get = (args: { jobClass: string | number } | [jobClass: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\LaravelQueueMetrics\Http\Controllers\JobMetricsController::show
* @see vendor/cboxdk/laravel-queue-metrics/src/Http/Controllers/JobMetricsController.php:20
* @route '/queue-metrics/jobs/{jobClass}'
*/
show.head = (args: { jobClass: string | number } | [jobClass: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const JobMetricsController = { show }

export default JobMetricsController