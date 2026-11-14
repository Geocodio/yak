import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\MarketplaceController::store
* @see app/Http/Controllers/MarketplaceController.php:14
* @route '/marketplaces'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/marketplaces',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\MarketplaceController::store
* @see app/Http/Controllers/MarketplaceController.php:14
* @route '/marketplaces'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\MarketplaceController::store
* @see app/Http/Controllers/MarketplaceController.php:14
* @route '/marketplaces'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\MarketplaceController::refresh
* @see app/Http/Controllers/MarketplaceController.php:34
* @route '/marketplaces/refresh'
*/
export const refresh = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(options),
    method: 'post',
})

refresh.definition = {
    methods: ["post"],
    url: '/marketplaces/refresh',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\MarketplaceController::refresh
* @see app/Http/Controllers/MarketplaceController.php:34
* @route '/marketplaces/refresh'
*/
refresh.url = (options?: RouteQueryOptions) => {
    return refresh.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\MarketplaceController::refresh
* @see app/Http/Controllers/MarketplaceController.php:34
* @route '/marketplaces/refresh'
*/
refresh.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: refresh.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\MarketplaceController::destroy
* @see app/Http/Controllers/MarketplaceController.php:25
* @route '/marketplaces/{name}'
*/
export const destroy = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/marketplaces/{name}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\MarketplaceController::destroy
* @see app/Http/Controllers/MarketplaceController.php:25
* @route '/marketplaces/{name}'
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
* @see \App\Http\Controllers\MarketplaceController::destroy
* @see app/Http/Controllers/MarketplaceController.php:25
* @route '/marketplaces/{name}'
*/
destroy.delete = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const MarketplaceController = { store, refresh, destroy }

export default MarketplaceController