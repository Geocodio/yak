import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Cli\RepositoryResolveController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/RepositoryResolveController.php:12
* @route '/api/cli/v1/repos/resolve'
*/
export const resolve = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resolve.url(options),
    method: 'post',
})

resolve.definition = {
    methods: ["post"],
    url: '/api/cli/v1/repos/resolve',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Cli\RepositoryResolveController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/RepositoryResolveController.php:12
* @route '/api/cli/v1/repos/resolve'
*/
resolve.url = (options?: RouteQueryOptions) => {
    return resolve.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cli\RepositoryResolveController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Cli/RepositoryResolveController.php:12
* @route '/api/cli/v1/repos/resolve'
*/
resolve.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: resolve.url(options),
    method: 'post',
})

const repos = {
    resolve: Object.assign(resolve, resolve),
}

export default repos