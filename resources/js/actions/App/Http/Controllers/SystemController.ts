import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults, validateParameters } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\SystemController::__invoke
* @see app/Http/Controllers/SystemController.php:28
* @route '/system/{path?}'
*/
const SystemController = (args?: { path?: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SystemController.url(args, options),
    method: 'get',
})

SystemController.definition = {
    methods: ["get","head"],
    url: '/system/{path?}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SystemController::__invoke
* @see app/Http/Controllers/SystemController.php:28
* @route '/system/{path?}'
*/
SystemController.url = (args?: { path?: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { path: args }
    }

    if (Array.isArray(args)) {
        args = {
            path: args[0],
        }
    }

    args = applyUrlDefaults(args)

    validateParameters(args, [
        "path",
    ])

    const parsedArgs = {
        path: args?.path,
    }

    return SystemController.definition.url
            .replace('{path?}', parsedArgs.path?.toString() ?? '')
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SystemController::__invoke
* @see app/Http/Controllers/SystemController.php:28
* @route '/system/{path?}'
*/
SystemController.get = (args?: { path?: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SystemController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SystemController::__invoke
* @see app/Http/Controllers/SystemController.php:28
* @route '/system/{path?}'
*/
SystemController.head = (args?: { path?: string | number } | [path: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: SystemController.url(args, options),
    method: 'head',
})

export default SystemController