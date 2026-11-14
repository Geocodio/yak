import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\SshAuthorizedKeysController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshAuthorizedKeysController.php:14
* @route '/internal/ssh/authorized-keys'
*/
const SshAuthorizedKeysController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SshAuthorizedKeysController.url(options),
    method: 'post',
})

SshAuthorizedKeysController.definition = {
    methods: ["post"],
    url: '/internal/ssh/authorized-keys',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SshAuthorizedKeysController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshAuthorizedKeysController.php:14
* @route '/internal/ssh/authorized-keys'
*/
SshAuthorizedKeysController.url = (options?: RouteQueryOptions) => {
    return SshAuthorizedKeysController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshAuthorizedKeysController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/SshAuthorizedKeysController.php:14
* @route '/internal/ssh/authorized-keys'
*/
SshAuthorizedKeysController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SshAuthorizedKeysController.url(options),
    method: 'post',
})

export default SshAuthorizedKeysController