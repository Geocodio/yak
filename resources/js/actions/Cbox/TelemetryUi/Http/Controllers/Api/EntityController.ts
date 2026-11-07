import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::index
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:25
* @route '/telemetry-ui/api/v2/entities/{type}'
*/
export const index = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/entities/{type}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::index
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:25
* @route '/telemetry-ui/api/v2/entities/{type}'
*/
index.url = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { type: args }
    }

    if (Array.isArray(args)) {
        args = {
            type: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        type: args.type,
    }

    return index.definition.url
            .replace('{type}', parsedArgs.type.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::index
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:25
* @route '/telemetry-ui/api/v2/entities/{type}'
*/
index.get = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::index
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:25
* @route '/telemetry-ui/api/v2/entities/{type}'
*/
index.head = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::show
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:46
* @route '/telemetry-ui/api/v2/entities/{type}/story'
*/
export const show = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/telemetry-ui/api/v2/entities/{type}/story',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::show
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:46
* @route '/telemetry-ui/api/v2/entities/{type}/story'
*/
show.url = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { type: args }
    }

    if (Array.isArray(args)) {
        args = {
            type: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        type: args.type,
    }

    return show.definition.url
            .replace('{type}', parsedArgs.type.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::show
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:46
* @route '/telemetry-ui/api/v2/entities/{type}/story'
*/
show.get = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Cbox\TelemetryUi\Http\Controllers\Api\EntityController::show
* @see vendor/cboxdk/laravel-telemetry-ui/src/Http/Controllers/Api/EntityController.php:46
* @route '/telemetry-ui/api/v2/entities/{type}/story'
*/
show.head = (args: { type: string | number } | [type: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const EntityController = { index, show }

export default EntityController