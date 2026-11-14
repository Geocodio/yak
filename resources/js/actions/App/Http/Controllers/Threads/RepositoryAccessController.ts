import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
const RepositoryAccessController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RepositoryAccessController.url(options),
    method: 'get',
})

RepositoryAccessController.definition = {
    methods: ["get","head"],
    url: '/threads/new/repository-access',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
RepositoryAccessController.url = (options?: RouteQueryOptions) => {
    return RepositoryAccessController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
RepositoryAccessController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RepositoryAccessController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
RepositoryAccessController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: RepositoryAccessController.url(options),
    method: 'head',
})

export default RepositoryAccessController