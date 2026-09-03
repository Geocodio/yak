import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import versions from './versions'
/**
* @see \App\Http\Controllers\PromptController::show
* @see app/Http/Controllers/PromptController.php:31
* @route '/prompts/{slug}'
*/
export const show = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/prompts/{slug}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PromptController::show
* @see app/Http/Controllers/PromptController.php:31
* @route '/prompts/{slug}'
*/
show.url = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{slug}', parsedArgs.slug.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PromptController::show
* @see app/Http/Controllers/PromptController.php:31
* @route '/prompts/{slug}'
*/
show.get = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PromptController::show
* @see app/Http/Controllers/PromptController.php:31
* @route '/prompts/{slug}'
*/
show.head = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\PromptController::update
* @see app/Http/Controllers/PromptController.php:45
* @route '/prompts/{slug}'
*/
export const update = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/prompts/{slug}',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\PromptController::update
* @see app/Http/Controllers/PromptController.php:45
* @route '/prompts/{slug}'
*/
update.url = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{slug}', parsedArgs.slug.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PromptController::update
* @see app/Http/Controllers/PromptController.php:45
* @route '/prompts/{slug}'
*/
update.put = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\PromptController::reset
* @see app/Http/Controllers/PromptController.php:98
* @route '/prompts/{slug}'
*/
export const reset = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: reset.url(args, options),
    method: 'delete',
})

reset.definition = {
    methods: ["delete"],
    url: '/prompts/{slug}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\PromptController::reset
* @see app/Http/Controllers/PromptController.php:98
* @route '/prompts/{slug}'
*/
reset.url = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return reset.definition.url
            .replace('{slug}', parsedArgs.slug.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PromptController::reset
* @see app/Http/Controllers/PromptController.php:98
* @route '/prompts/{slug}'
*/
reset.delete = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: reset.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\PromptPreviewController::__invoke
* @see app/Http/Controllers/PromptPreviewController.php:12
* @route '/prompts/{slug}/preview'
*/
export const preview = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: preview.url(args, options),
    method: 'post',
})

preview.definition = {
    methods: ["post"],
    url: '/prompts/{slug}/preview',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\PromptPreviewController::__invoke
* @see app/Http/Controllers/PromptPreviewController.php:12
* @route '/prompts/{slug}/preview'
*/
preview.url = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return preview.definition.url
            .replace('{slug}', parsedArgs.slug.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PromptPreviewController::__invoke
* @see app/Http/Controllers/PromptPreviewController.php:12
* @route '/prompts/{slug}/preview'
*/
preview.post = (args: { slug: string | number } | [slug: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: preview.url(args, options),
    method: 'post',
})

const prompts = {
    show: Object.assign(show, show),
    update: Object.assign(update, update),
    reset: Object.assign(reset, reset),
    preview: Object.assign(preview, preview),
    versions: Object.assign(versions, versions),
}

export default prompts