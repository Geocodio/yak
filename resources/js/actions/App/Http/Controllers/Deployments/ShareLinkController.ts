import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::store
* @see app/Http/Controllers/Deployments/ShareLinkController.php:13
* @route '/deployments/{deployment}/share'
*/
export const store = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/deployments/{deployment}/share',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::store
* @see app/Http/Controllers/Deployments/ShareLinkController.php:13
* @route '/deployments/{deployment}/share'
*/
store.url = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deployment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { deployment: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            deployment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        deployment: typeof args.deployment === 'object'
        ? args.deployment.id
        : args.deployment,
    }

    return store.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::store
* @see app/Http/Controllers/Deployments/ShareLinkController.php:13
* @route '/deployments/{deployment}/share'
*/
store.post = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::destroy
* @see app/Http/Controllers/Deployments/ShareLinkController.php:26
* @route '/deployments/{deployment}/share'
*/
export const destroy = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/deployments/{deployment}/share',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::destroy
* @see app/Http/Controllers/Deployments/ShareLinkController.php:26
* @route '/deployments/{deployment}/share'
*/
destroy.url = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deployment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { deployment: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            deployment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        deployment: typeof args.deployment === 'object'
        ? args.deployment.id
        : args.deployment,
    }

    return destroy.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::destroy
* @see app/Http/Controllers/Deployments/ShareLinkController.php:26
* @route '/deployments/{deployment}/share'
*/
destroy.delete = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const ShareLinkController = { store, destroy }

export default ShareLinkController