import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:38
* @route '/telemetry-ui/api/v2/traces/{traceId}'
*/
const TraceController = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TraceController.url(args, options),
    method: 'get',
})

TraceController.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/traces/{traceId}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:38
* @route '/telemetry-ui/api/v2/traces/{traceId}'
*/
TraceController.url = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { traceId: args }
    }

    if (Array.isArray(args)) {
        args = {
            traceId: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        traceId: args.traceId,
    }

    return TraceController.definition.url
            .replace('{traceId}', parsedArgs.traceId.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:38
* @route '/telemetry-ui/api/v2/traces/{traceId}'
*/
TraceController.get = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TraceController.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::__invoke
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:38
* @route '/telemetry-ui/api/v2/traces/{traceId}'
*/
TraceController.head = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: TraceController.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::context
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:101
* @route '/telemetry-ui/api/v2/traces/{traceId}/context'
*/
export const context = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: context.url(args, options),
    method: 'get',
})

context.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/traces/{traceId}/context',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::context
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:101
* @route '/telemetry-ui/api/v2/traces/{traceId}/context'
*/
context.url = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { traceId: args }
    }

    if (Array.isArray(args)) {
        args = {
            traceId: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        traceId: args.traceId,
    }

    return context.definition.url
            .replace('{traceId}', parsedArgs.traceId.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::context
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:101
* @route '/telemetry-ui/api/v2/traces/{traceId}/context'
*/
context.get = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: context.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::context
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:101
* @route '/telemetry-ui/api/v2/traces/{traceId}/context'
*/
context.head = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: context.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::markdown
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:131
* @route '/telemetry-ui/api/v2/traces/{traceId}/markdown'
*/
export const markdown = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: markdown.url(args, options),
    method: 'get',
})

markdown.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/traces/{traceId}/markdown',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::markdown
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:131
* @route '/telemetry-ui/api/v2/traces/{traceId}/markdown'
*/
markdown.url = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { traceId: args }
    }

    if (Array.isArray(args)) {
        args = {
            traceId: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        traceId: args.traceId,
    }

    return markdown.definition.url
            .replace('{traceId}', parsedArgs.traceId.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::markdown
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:131
* @route '/telemetry-ui/api/v2/traces/{traceId}/markdown'
*/
markdown.get = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: markdown.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\TraceController::markdown
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/TraceController.php:131
* @route '/telemetry-ui/api/v2/traces/{traceId}/markdown'
*/
markdown.head = (args: { traceId: string | number } | [traceId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: markdown.url(args, options),
    method: 'head',
})

TraceController.context = context
TraceController.markdown = markdown

export default TraceController