import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Cli\DeviceCodeController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/DeviceCodeController.php:13
* @route '/api/cli/v1/device/code'
*/
const DeviceCodeController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DeviceCodeController.url(options),
    method: 'post',
})

DeviceCodeController.definition = {
    methods: ["post"],
    url: '/api/cli/v1/device/code',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Cli\DeviceCodeController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/DeviceCodeController.php:13
* @route '/api/cli/v1/device/code'
*/
DeviceCodeController.url = (options?: RouteQueryOptions) => {
    return DeviceCodeController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cli\DeviceCodeController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/DeviceCodeController.php:13
* @route '/api/cli/v1/device/code'
*/
DeviceCodeController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: DeviceCodeController.url(options),
    method: 'post',
})

export default DeviceCodeController