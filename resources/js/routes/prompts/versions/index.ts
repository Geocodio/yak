import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\PromptVersionController::show
* @see app/Http/Controllers/PromptVersionController.php:12
* @route '/prompts/{slug}/versions/{version}'
*/
export const show = (args: { slug: string | number, version: string | number } | [slug: string | number, version: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/prompts/{slug}/versions/{version}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PromptVersionController::show
* @see app/Http/Controllers/PromptVersionController.php:12
* @route '/prompts/{slug}/versions/{version}'
*/
show.url = (args: { slug: string | number, version: string | number } | [slug: string | number, version: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            slug: args[0],
            version: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        slug: args.slug,
        version: args.version,
    }

    return show.definition.url
            .replace('{slug}', parsedArgs.slug.toString())
            .replace('{version}', parsedArgs.version.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PromptVersionController::show
* @see app/Http/Controllers/PromptVersionController.php:12
* @route '/prompts/{slug}/versions/{version}'
*/
show.get = (args: { slug: string | number, version: string | number } | [slug: string | number, version: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PromptVersionController::show
* @see app/Http/Controllers/PromptVersionController.php:12
* @route '/prompts/{slug}/versions/{version}'
*/
show.head = (args: { slug: string | number, version: string | number } | [slug: string | number, version: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const versions = {
    show: Object.assign(show, show),
}

export default versions