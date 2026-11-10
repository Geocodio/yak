import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::updateHibernation
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:16
* @route '/deployments/{deployment}/hibernation'
*/
export const updateHibernation = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updateHibernation.url(args, options),
    method: 'patch',
})

updateHibernation.definition = {
    methods: ["patch"],
    url: '/deployments/{deployment}/hibernation',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::updateHibernation
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:16
* @route '/deployments/{deployment}/hibernation'
*/
updateHibernation.url = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return updateHibernation.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::updateHibernation
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:16
* @route '/deployments/{deployment}/hibernation'
*/
updateHibernation.patch = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updateHibernation.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::rebuild
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:51
* @route '/deployments/{deployment}/rebuild'
*/
export const rebuild = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rebuild.url(args, options),
    method: 'post',
})

rebuild.definition = {
    methods: ["post"],
    url: '/deployments/{deployment}/rebuild',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::rebuild
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:51
* @route '/deployments/{deployment}/rebuild'
*/
rebuild.url = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return rebuild.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::rebuild
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:51
* @route '/deployments/{deployment}/rebuild'
*/
rebuild.post = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rebuild.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::destroy
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:58
* @route '/deployments/{deployment}'
*/
export const destroy = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/deployments/{deployment}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::destroy
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:58
* @route '/deployments/{deployment}'
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
* @see \App\Http\Controllers\Deployments\DeploymentActionController::destroy
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:58
* @route '/deployments/{deployment}'
*/
destroy.delete = (args: { deployment: string | number | { id: string | number } } | [deployment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const DeploymentActionController = { updateHibernation, rebuild, destroy }

export default DeploymentActionController