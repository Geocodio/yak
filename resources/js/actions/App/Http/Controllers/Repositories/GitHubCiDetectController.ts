import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\GitHubCiDetectController::__invoke
* @see app/Http/Controllers/Repositories/GitHubCiDetectController.php:18
* @route '/repos/github-detect'
*/
const GitHubCiDetectController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: GitHubCiDetectController.url(options),
    method: 'get',
})

GitHubCiDetectController.definition = {
    methods: ["get","head"],
    url: '/repos/github-detect',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\GitHubCiDetectController::__invoke
* @see app/Http/Controllers/Repositories/GitHubCiDetectController.php:18
* @route '/repos/github-detect'
*/
GitHubCiDetectController.url = (options?: RouteQueryOptions) => {
    return GitHubCiDetectController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\GitHubCiDetectController::__invoke
* @see app/Http/Controllers/Repositories/GitHubCiDetectController.php:18
* @route '/repos/github-detect'
*/
GitHubCiDetectController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: GitHubCiDetectController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\GitHubCiDetectController::__invoke
* @see app/Http/Controllers/Repositories/GitHubCiDetectController.php:18
* @route '/repos/github-detect'
*/
GitHubCiDetectController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: GitHubCiDetectController.url(options),
    method: 'head',
})

export default GitHubCiDetectController