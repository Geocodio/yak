import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:19
* @route '/deployments'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/deployments',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:19
* @route '/deployments'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:19
* @route '/deployments'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::index
* @see app/Http/Controllers/Deployments/DeploymentController.php:19
* @route '/deployments'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:31
* @route '/deployments/{deployment}'
*/
export const show = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/deployments/{deployment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:31
* @route '/deployments/{deployment}'
*/
show.url = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:31
* @route '/deployments/{deployment}'
*/
show.get = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentController::show
* @see app/Http/Controllers/Deployments/DeploymentController.php:31
* @route '/deployments/{deployment}'
*/
show.head = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const DeploymentController = { index, show }

export default DeploymentController