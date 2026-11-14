import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Cli\DeviceTokenController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/DeviceTokenController.php:13
* @route '/api/cli/v1/device/token'
*/
const DeviceTokenController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DeviceTokenController.url(options),
    method: 'post',
})

DeviceTokenController.definition = {
    methods: ["post"],
    url: '/api/cli/v1/device/token',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Cli\DeviceTokenController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/DeviceTokenController.php:13
* @route '/api/cli/v1/device/token'
*/
DeviceTokenController.url = (options?: RouteQueryOptions) => {
    return DeviceTokenController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cli\DeviceTokenController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/DeviceTokenController.php:13
* @route '/api/cli/v1/device/token'
*/
DeviceTokenController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DeviceTokenController.url(options),
    method: 'post',
})

export default DeviceTokenController