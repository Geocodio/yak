import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\McpLoginController::redirect
* @see app/Http/Controllers/Settings/McpLoginController.php:28
* @route '/mcp/{name}/login/redirect'
*/
export const redirect = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: redirect.url(args, options),
    method: 'post',
})

redirect.definition = {
    methods: ["post"],
    url: '/mcp/{name}/login/redirect',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\McpLoginController::redirect
* @see app/Http/Controllers/Settings/McpLoginController.php:28
* @route '/mcp/{name}/login/redirect'
*/
redirect.url = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { name: args }
    }

    if (Array.isArray(args)) {
        args = {
            name: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        name: args.name,
    }

    return redirect.definition.url
            .replace('{name}', parsedArgs.name.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\McpLoginController::redirect
* @see app/Http/Controllers/Settings/McpLoginController.php:28
* @route '/mcp/{name}/login/redirect'
*/
redirect.post = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: redirect.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\McpLoginController::start
* @see app/Http/Controllers/Settings/McpLoginController.php:13
* @route '/mcp/{name}/login'
*/
export const start = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(args, options),
    method: 'post',
})

start.definition = {
    methods: ["post"],
    url: '/mcp/{name}/login',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\McpLoginController::start
* @see app/Http/Controllers/Settings/McpLoginController.php:13
* @route '/mcp/{name}/login'
*/
start.url = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { name: args }
    }

    if (Array.isArray(args)) {
        args = {
            name: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        name: args.name,
    }

    return start.definition.url
            .replace('{name}', parsedArgs.name.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\McpLoginController::start
* @see app/Http/Controllers/Settings/McpLoginController.php:13
* @route '/mcp/{name}/login'
*/
start.post = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: start.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\McpLoginController::cancel
* @see app/Http/Controllers/Settings/McpLoginController.php:53
* @route '/mcp/{name}/login'
*/
export const cancel = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: cancel.url(args, options),
    method: 'delete',
})

cancel.definition = {
    methods: ["delete"],
    url: '/mcp/{name}/login',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\McpLoginController::cancel
* @see app/Http/Controllers/Settings/McpLoginController.php:53
* @route '/mcp/{name}/login'
*/
cancel.url = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { name: args }
    }

    if (Array.isArray(args)) {
        args = {
            name: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        name: args.name,
    }

    return cancel.definition.url
            .replace('{name}', parsedArgs.name.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\McpLoginController::cancel
* @see app/Http/Controllers/Settings/McpLoginController.php:53
* @route '/mcp/{name}/login'
*/
cancel.delete = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: cancel.url(args, options),
    method: 'delete',
})

const McpLoginController = { redirect, start, cancel }

export default McpLoginController