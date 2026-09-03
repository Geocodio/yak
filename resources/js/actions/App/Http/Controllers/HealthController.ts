import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\HealthController::index
* @see app/Http/Controllers/HealthController.php:53
* @route '/health'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/health',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\HealthController::index
* @see app/Http/Controllers/HealthController.php:53
* @route '/health'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\HealthController::index
* @see app/Http/Controllers/HealthController.php:53
* @route '/health'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\HealthController::index
* @see app/Http/Controllers/HealthController.php:53
* @route '/health'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\HealthController::refreshAll
* @see app/Http/Controllers/HealthController.php:68
* @route '/health/refresh'
*/
export const refreshAll = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refreshAll.url(options),
    method: 'post',
})

refreshAll.definition = {
    methods: ["post"],
    url: '/health/refresh',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\HealthController::refreshAll
* @see app/Http/Controllers/HealthController.php:68
* @route '/health/refresh'
*/
refreshAll.url = (options?: RouteQueryOptions) => {
    return refreshAll.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\HealthController::refreshAll
* @see app/Http/Controllers/HealthController.php:68
* @route '/health/refresh'
*/
refreshAll.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refreshAll.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\HealthController::refreshOne
* @see app/Http/Controllers/HealthController.php:79
* @route '/health/{check}/refresh'
*/
export const refreshOne = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refreshOne.url(args, options),
    method: 'post',
})

refreshOne.definition = {
    methods: ["post"],
    url: '/health/{check}/refresh',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\HealthController::refreshOne
* @see app/Http/Controllers/HealthController.php:79
* @route '/health/{check}/refresh'
*/
refreshOne.url = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return refreshOne.definition.url
            .replace('{check}', parsedArgs.check.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\HealthController::refreshOne
* @see app/Http/Controllers/HealthController.php:79
* @route '/health/{check}/refresh'
*/
refreshOne.post = (args: { check: string | number } | [check: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refreshOne.url(args, options),
    method: 'post',
})

const HealthController = { index, refreshAll, refreshOne }

export default HealthController