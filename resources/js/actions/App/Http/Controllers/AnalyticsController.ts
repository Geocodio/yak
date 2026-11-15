import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\AnalyticsController::__invoke
* @see app/Http/Controllers/AnalyticsController.php:15
* @route '/analytics'
*/
const AnalyticsController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AnalyticsController.url(options),
    method: 'get',
})

AnalyticsController.definition = {
    methods: ["get","head"],
    url: '/analytics',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AnalyticsController::__invoke
* @see app/Http/Controllers/AnalyticsController.php:15
* @route '/analytics'
*/
AnalyticsController.url = (options?: RouteQueryOptions) => {
    return AnalyticsController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AnalyticsController::__invoke
* @see app/Http/Controllers/AnalyticsController.php:15
* @route '/analytics'
*/
AnalyticsController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AnalyticsController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AnalyticsController::__invoke
* @see app/Http/Controllers/AnalyticsController.php:15
* @route '/analytics'
*/
AnalyticsController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: AnalyticsController.url(options),
    method: 'head',
})

export default AnalyticsController