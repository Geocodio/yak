import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Cli\DeviceController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/DeviceController.php:13
* @route '/api/cli/v1/device'
*/
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/api/cli/v1/device',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Cli\DeviceController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/DeviceController.php:13
* @route '/api/cli/v1/device'
*/
destroy.url = (options?: RouteQueryOptions) => {
    return destroy.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cli\DeviceController::destroy
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/DeviceController.php:13
* @route '/api/cli/v1/device'
*/
destroy.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(options),
    method: 'delete',
})

const DeviceController = { destroy }

export default DeviceController