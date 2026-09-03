import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
import check from './check'
/**
* @see \App\Http\Controllers\HealthController::refresh
* @see app/Http/Controllers/HealthController.php:68
* @route '/health/refresh'
*/
export const refresh = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(options),
    method: 'post',
})

refresh.definition = {
    methods: ["post"],
    url: '/health/refresh',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\HealthController::refresh
* @see app/Http/Controllers/HealthController.php:68
* @route '/health/refresh'
*/
refresh.url = (options?: RouteQueryOptions) => {
    return refresh.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\HealthController::refresh
* @see app/Http/Controllers/HealthController.php:68
* @route '/health/refresh'
*/
refresh.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(options),
    method: 'post',
})

const health = {
    refresh: Object.assign(refresh, refresh),
    check: Object.assign(check, check),
}

export default health