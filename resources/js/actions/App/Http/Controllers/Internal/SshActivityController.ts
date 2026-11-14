import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\SshActivityController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshActivityController.php:17
* @route '/internal/ssh/activity'
*/
const SshActivityController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SshActivityController.url(options),
    method: 'post',
})

SshActivityController.definition = {
    methods: ["post"],
    url: '/internal/ssh/activity',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SshActivityController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshActivityController.php:17
* @route '/internal/ssh/activity'
*/
SshActivityController.url = (options?: RouteQueryOptions) => {
    return SshActivityController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshActivityController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshActivityController.php:17
* @route '/internal/ssh/activity'
*/
SshActivityController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SshActivityController.url(options),
    method: 'post',
})

export default SshActivityController