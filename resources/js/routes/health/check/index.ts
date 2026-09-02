import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\HealthController::refresh
* @see app/Http/Controllers/HealthController.php:78
* @route '/health/{check}/refresh'
*/
export const refresh = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(args, options),
    method: 'post',
})

refresh.definition = {
    methods: ["post"],
    url: '/health/{check}/refresh',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\HealthController::refresh
* @see app/Http/Controllers/HealthController.php:78
* @route '/health/{check}/refresh'
*/
refresh.url = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { check: args }
    }

    if (Array.isArray(args)) {
        args = {
            check: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        check: args.check,
    }

    return refresh.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\HealthController::refresh
* @see app/Http/Controllers/HealthController.php:78
* @route '/health/{check}/refresh'
*/
refresh.post = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(args, options),
    method: 'post',
})

const check = {
    refresh: Object.assign(refresh, refresh),
}

export default check