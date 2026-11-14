import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\PreviewAuthController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/PreviewAuthController.php:17
* @route '/internal/deployments/preview-auth'
*/
const PreviewAuthController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PreviewAuthController.url(options),
    method: 'get',
})

PreviewAuthController.definition = {
    methods: ["get","head"],
    url: '/internal/deployments/preview-auth',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\PreviewAuthController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/PreviewAuthController.php:17
* @route '/internal/deployments/preview-auth'
*/
PreviewAuthController.url = (options?: RouteQueryOptions) => {
    return PreviewAuthController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\PreviewAuthController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/PreviewAuthController.php:17
* @route '/internal/deployments/preview-auth'
*/
PreviewAuthController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PreviewAuthController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\PreviewAuthController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Internal/PreviewAuthController.php:17
* @route '/internal/deployments/preview-auth'
*/
PreviewAuthController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PreviewAuthController.url(options),
    method: 'head',
})

export default PreviewAuthController