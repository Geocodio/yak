import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\GitHubRepoSearchController::__invoke
* @see app/Http/Controllers/Repositories/GitHubRepoSearchController.php:14
* @route '/repos/github-search'
*/
const GitHubRepoSearchController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: GitHubRepoSearchController.url(options),
    method: 'get',
})

GitHubRepoSearchController.definition = {
    methods: ["get","head"],
    url: '/repos/github-search',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\GitHubRepoSearchController::__invoke
* @see app/Http/Controllers/Repositories/GitHubRepoSearchController.php:14
* @route '/repos/github-search'
*/
GitHubRepoSearchController.url = (options?: RouteQueryOptions) => {
    return GitHubRepoSearchController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\GitHubRepoSearchController::__invoke
* @see app/Http/Controllers/Repositories/GitHubRepoSearchController.php:14
* @route '/repos/github-search'
*/
GitHubRepoSearchController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: GitHubRepoSearchController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\GitHubRepoSearchController::__invoke
* @see app/Http/Controllers/Repositories/GitHubRepoSearchController.php:14
* @route '/repos/github-search'
*/
GitHubRepoSearchController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: GitHubRepoSearchController.url(options),
    method: 'head',
})

export default GitHubRepoSearchController