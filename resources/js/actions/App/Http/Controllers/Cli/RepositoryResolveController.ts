import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Cli\RepositoryResolveController::__invoke
* @see app/Http/Controllers/Cli/RepositoryResolveController.php:12
* @route '/api/cli/v1/repos/resolve'
*/
const RepositoryResolveController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RepositoryResolveController.url(options),
    method: 'post',
})

RepositoryResolveController.definition = {
    methods: ["post"],
    url: '/api/cli/v1/repos/resolve',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Cli\RepositoryResolveController::__invoke
* @see app/Http/Controllers/Cli/RepositoryResolveController.php:12
* @route '/api/cli/v1/repos/resolve'
*/
RepositoryResolveController.url = (options?: RouteQueryOptions) => {
    return RepositoryResolveController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Cli\RepositoryResolveController::__invoke
* @see app/Http/Controllers/Cli/RepositoryResolveController.php:12
* @route '/api/cli/v1/repos/resolve'
*/
RepositoryResolveController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RepositoryResolveController.url(options),
    method: 'post',
})

export default RepositoryResolveController