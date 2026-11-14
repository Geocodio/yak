import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::store
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Deployments/ShareLinkController.php:14
* @route '/deployments/{deployment}/share'
*/
export const store = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/deployments/{deployment}/share',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::store
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Deployments/ShareLinkController.php:14
* @route '/deployments/{deployment}/share'
*/
store.url = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deployment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { deployment: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            deployment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        deployment: typeof args.deployment === 'object'
        ? args.deployment.public_id
        : args.deployment,
    }

    return store.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::store
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Deployments/ShareLinkController.php:14
* @route '/deployments/{deployment}/share'
*/
store.post = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Deployments/ShareLinkController.php:29
* @route '/deployments/{deployment}/share'
*/
export const destroy = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/deployments/{deployment}/share',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Deployments/ShareLinkController.php:29
* @route '/deployments/{deployment}/share'
*/
destroy.url = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deployment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { deployment: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            deployment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        deployment: typeof args.deployment === 'object'
        ? args.deployment.public_id
        : args.deployment,
    }

    return destroy.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\ShareLinkController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Deployments/ShareLinkController.php:29
* @route '/deployments/{deployment}/share'
*/
destroy.delete = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const share = {
    store: Object.assign(store, store),
    destroy: Object.assign(destroy, destroy),
}

export default share