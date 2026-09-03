import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import login from './login'
/**
* @see \App\Http\Controllers\Settings\McpServerController::store
* @see app/Http/Controllers/Settings/McpServerController.php:40
* @route '/mcp'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/mcp',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\McpServerController::store
* @see app/Http/Controllers/Settings/McpServerController.php:40
* @route '/mcp'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\McpServerController::store
* @see app/Http/Controllers/Settings/McpServerController.php:40
* @route '/mcp'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\McpServerController::logout
* @see app/Http/Controllers/Settings/McpServerController.php:107
* @route '/mcp/{name}/logout'
*/
export const logout = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: logout.url(args, options),
    method: 'post',
})

logout.definition = {
    methods: ["post"],
    url: '/mcp/{name}/logout',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\McpServerController::logout
* @see app/Http/Controllers/Settings/McpServerController.php:107
* @route '/mcp/{name}/logout'
*/
logout.url = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return logout.definition.url
            .replace('{name}', parsedArgs.name.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\McpServerController::logout
* @see app/Http/Controllers/Settings/McpServerController.php:107
* @route '/mcp/{name}/logout'
*/
logout.post = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: logout.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\McpServerController::destroy
* @see app/Http/Controllers/Settings/McpServerController.php:80
* @route '/mcp/{name}'
*/
export const destroy = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/mcp/{name}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\McpServerController::destroy
* @see app/Http/Controllers/Settings/McpServerController.php:80
* @route '/mcp/{name}'
*/
destroy.url = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return destroy.definition.url
            .replace('{name}', parsedArgs.name.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\McpServerController::destroy
* @see app/Http/Controllers/Settings/McpServerController.php:80
* @route '/mcp/{name}'
*/
destroy.delete = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const mcp = {
    store: Object.assign(store, store),
    login: Object.assign(login, login),
    logout: Object.assign(logout, logout),
    destroy: Object.assign(destroy, destroy),
}

export default mcp