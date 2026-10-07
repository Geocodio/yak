import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::update
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:16
* @route '/deployments/{deployment}/hibernation'
*/
export const update = (args: { deployment: number | { id: number } } | [deployment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/deployments/{deployment}/hibernation',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::update
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:16
* @route '/deployments/{deployment}/hibernation'
*/
update.url = (args: { deployment: number | { id: number } } | [deployment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Deployments\DeploymentActionController::update
* @see app/Http/Controllers/Deployments/DeploymentActionController.php:16
* @route '/deployments/{deployment}/hibernation'
*/
update.patch = (args: { deployment: number | { id: number } } | [deployment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

const hibernation = {
    update: Object.assign(update, update),
}

export default hibernation