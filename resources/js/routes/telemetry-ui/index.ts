import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults, validateParameters } from './../../wayfinder'
import api from './api'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\AssetController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/AssetController.php:27
* @route '/telemetry-ui/build/{path}'
*/
export const asset = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: asset.url(args, options),
    method: 'get',
})

asset.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/build/{path}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\AssetController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/AssetController.php:27
* @route '/telemetry-ui/build/{path}'
*/
asset.url = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { path: args }
    }

    if (Array.isArray(args)) {
        args = {
            path: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        path: args.path,
    }

    return asset.definition.url
            .replace('{path}', parsedArgs.path.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\AssetController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/AssetController.php:27
* @route '/telemetry-ui/build/{path}'
*/
asset.get = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: asset.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\AssetController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/AssetController.php:27
* @route '/telemetry-ui/build/{path}'
*/
asset.head = (args: { path: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: asset.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\SpaController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/SpaController.php:23
* @route '/telemetry-ui/{any?}'
*/
export const spa = (args?: { any?: string | number } | [any: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: spa.url(args, options),
    method: 'get',
})

spa.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/{any?}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\SpaController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/SpaController.php:23
* @route '/telemetry-ui/{any?}'
*/
spa.url = (args?: { any?: string | number } | [any: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { any: args }
    }

    if (Array.isArray(args)) {
        args = {
            any: args[0],
        }
    }

    args = applyUrlDefaults(args)

    validateParameters(args, [
        "any",
    ])

    const parsedArgs = {
        any: args?.any,
    }

    return spa.definition.url
            .replace('{any?}', parsedArgs.any?.toString() ?? '')
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\SpaController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/SpaController.php:23
* @route '/telemetry-ui/{any?}'
*/
spa.get = (args?: { any?: string | number } | [any: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: spa.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\SpaController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/SpaController.php:23
* @route '/telemetry-ui/{any?}'
*/
spa.head = (args?: { any?: string | number } | [any: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: spa.url(args, options),
    method: 'head',
})

const telemetryUi = {
    asset: Object.assign(asset, asset),
    api: Object.assign(api, api),
    spa: Object.assign(spa, spa),
}

export default telemetryUi