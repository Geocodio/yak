import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults, validateParameters } from './../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\SpaController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/SpaController.php:23
* @route '/telemetry-ui/{any?}'
*/
const SpaController = (args?: { any?: string | number } | [any: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SpaController.url(args, options),
    method: 'get',
})

SpaController.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/{any?}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\SpaController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/SpaController.php:23
* @route '/telemetry-ui/{any?}'
*/
SpaController.url = (args?: { any?: string | number } | [any: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return SpaController.definition.url
            .replace('{any?}', parsedArgs.any?.toString() ?? '')
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\SpaController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/SpaController.php:23
* @route '/telemetry-ui/{any?}'
*/
SpaController.get = (args?: { any?: string | number } | [any: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SpaController.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\SpaController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/SpaController.php:23
* @route '/telemetry-ui/{any?}'
*/
SpaController.head = (args?: { any?: string | number } | [any: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: SpaController.url(args, options),
    method: 'head',
})

export default SpaController