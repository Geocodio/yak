import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\SshWakeController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshWakeController.php:14
* @route '/internal/ssh/wake'
*/
const SshWakeController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SshWakeController.url(options),
    method: 'post',
})

SshWakeController.definition = {
    methods: ["post"],
    url: '/internal/ssh/wake',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SshWakeController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshWakeController.php:14
* @route '/internal/ssh/wake'
*/
SshWakeController.url = (options?: RouteQueryOptions) => {
    return SshWakeController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshWakeController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshWakeController.php:14
* @route '/internal/ssh/wake'
*/
SshWakeController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SshWakeController.url(options),
    method: 'post',
})

export default SshWakeController