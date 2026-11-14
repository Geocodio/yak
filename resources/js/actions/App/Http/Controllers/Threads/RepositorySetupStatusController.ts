import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\RepositorySetupStatusController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/RepositorySetupStatusController.php:16
* @route '/threads/new/repository-setup'
*/
const RepositorySetupStatusController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RepositorySetupStatusController.url(options),
    method: 'get',
})

RepositorySetupStatusController.definition = {
    methods: ["get","head"],
    url: '/threads/new/repository-setup',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\RepositorySetupStatusController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/RepositorySetupStatusController.php:16
* @route '/threads/new/repository-setup'
*/
RepositorySetupStatusController.url = (options?: RouteQueryOptions) => {
    return RepositorySetupStatusController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\RepositorySetupStatusController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/RepositorySetupStatusController.php:16
* @route '/threads/new/repository-setup'
*/
RepositorySetupStatusController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RepositorySetupStatusController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\RepositorySetupStatusController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/RepositorySetupStatusController.php:16
* @route '/threads/new/repository-setup'
*/
RepositorySetupStatusController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: RepositorySetupStatusController.url(options),
    method: 'head',
})

export default RepositorySetupStatusController