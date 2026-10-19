import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Repositories\RiskProfileController::__invoke
* @see app/Http/Controllers/Repositories/RiskProfileController.php:17
* @route '/repos/{repository}/risk-profile'
*/
const RiskProfileController = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RiskProfileController.url(args, options),
    method: 'get',
})

RiskProfileController.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/risk-profile',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Repositories\RiskProfileController::__invoke
* @see app/Http/Controllers/Repositories/RiskProfileController.php:17
* @route '/repos/{repository}/risk-profile'
*/
RiskProfileController.url = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { repository: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'slug' in args) {
        args = { repository: args.slug }
    }

    if (Array.isArray(args)) {
        args = {
            repository: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repository: typeof args.repository === 'object'
        ? args.repository.slug
        : args.repository,
    }

    return RiskProfileController.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Repositories\RiskProfileController::__invoke
* @see app/Http/Controllers/Repositories/RiskProfileController.php:17
* @route '/repos/{repository}/risk-profile'
*/
RiskProfileController.get = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RiskProfileController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Repositories\RiskProfileController::__invoke
* @see app/Http/Controllers/Repositories/RiskProfileController.php:17
* @route '/repos/{repository}/risk-profile'
*/
RiskProfileController.head = (args: { repository: string | number | { slug: string | number } } | [repository: string | number | { slug: string | number } ] | string | number | { slug: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: RiskProfileController.url(args, options),
    method: 'head',
})

export default RiskProfileController