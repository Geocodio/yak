import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/app/embed/deployments'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/app/embed/deployments',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/app/embed/deployments'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/app/embed/deployments'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:21
* @route '/app/embed/deployments'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/app/embed/deployments/{deployment}'
*/
export const show = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/app/embed/deployments/{deployment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/app/embed/deployments/{deployment}'
*/
show.url = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/app/embed/deployments/{deployment}'
*/
show.get = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:33
* @route '/app/embed/deployments/{deployment}'
*/
show.head = (args: { deployment: string | { public_id: string } } | [deployment: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const deployments = {
    index: Object.assign(index, index),
    show: Object.assign(show, show),
}

export default deployments