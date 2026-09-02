import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\PromptPreviewController::__invoke
* @see app/Http/Controllers/PromptPreviewController.php:16
* @route '/prompts/{slug}/preview'
*/
const PromptPreviewController = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: PromptPreviewController.url(args, options),
    method: 'post',
})

PromptPreviewController.definition = {
    methods: ["post"],
    url: '/prompts/{slug}/preview',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\PromptPreviewController::__invoke
* @see app/Http/Controllers/PromptPreviewController.php:16
* @route '/prompts/{slug}/preview'
*/
PromptPreviewController.url = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { slug: args }
    }

    if (Array.isArray(args)) {
        args = {
            slug: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        slug: args.slug,
    }

    return PromptPreviewController.definition.url
            .replace('{slug}', parsedArgs.slug.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PromptPreviewController::__invoke
* @see app/Http/Controllers/PromptPreviewController.php:16
* @route '/prompts/{slug}/preview'
*/
PromptPreviewController.post = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: PromptPreviewController.url(args, options),
    method: 'post',
})

export default PromptPreviewController