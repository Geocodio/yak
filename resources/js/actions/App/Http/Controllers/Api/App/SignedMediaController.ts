import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::attachment
* @see app/Http/Controllers/Api/App/SignedMediaController.php:23
* @route '/app-media/task-attachments/{attachment}'
*/
export const attachment = (args: { attachment: string | number } | [attachment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: attachment.url(args, options),
    method: 'get',
})

attachment.definition = {
    methods: ["get","head"],
    url: '/app-media/task-attachments/{attachment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::attachment
* @see app/Http/Controllers/Api/App/SignedMediaController.php:23
* @route '/app-media/task-attachments/{attachment}'
*/
attachment.url = (args: { attachment: string | number } | [attachment: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { attachment: args }
    }

    if (Array.isArray(args)) {
        args = {
            attachment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        attachment: args.attachment,
    }

    return attachment.definition.url
            .replace('{attachment}', parsedArgs.attachment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::attachment
* @see app/Http/Controllers/Api/App/SignedMediaController.php:23
* @route '/app-media/task-attachments/{attachment}'
*/
attachment.get = (args: { attachment: string | number } | [attachment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: attachment.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::attachment
* @see app/Http/Controllers/Api/App/SignedMediaController.php:23
* @route '/app-media/task-attachments/{attachment}'
*/
attachment.head = (args: { attachment: string | number } | [attachment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: attachment.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::pullRequestImage
* @see app/Http/Controllers/Api/App/SignedMediaController.php:28
* @route '/app-media/tasks/{task}/pr-images/{asset}'
*/
export const pullRequestImage = (args: { task: string | number, asset: string | number } | [task: string | number, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: pullRequestImage.url(args, options),
    method: 'get',
})

pullRequestImage.definition = {
    methods: ["get","head"],
    url: '/app-media/tasks/{task}/pr-images/{asset}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::pullRequestImage
* @see app/Http/Controllers/Api/App/SignedMediaController.php:28
* @route '/app-media/tasks/{task}/pr-images/{asset}'
*/
pullRequestImage.url = (args: { task: string | number, asset: string | number } | [task: string | number, asset: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            task: args[0],
            asset: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: args.task,
        asset: args.asset,
    }

    return pullRequestImage.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace('{asset}', parsedArgs.asset.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::pullRequestImage
* @see app/Http/Controllers/Api/App/SignedMediaController.php:28
* @route '/app-media/tasks/{task}/pr-images/{asset}'
*/
pullRequestImage.get = (args: { task: string | number, asset: string | number } | [task: string | number, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: pullRequestImage.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::pullRequestImage
* @see app/Http/Controllers/Api/App/SignedMediaController.php:28
* @route '/app-media/tasks/{task}/pr-images/{asset}'
*/
pullRequestImage.head = (args: { task: string | number, asset: string | number } | [task: string | number, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: pullRequestImage.url(args, options),
    method: 'head',
})

const SignedMediaController = { attachment, pullRequestImage }

export default SignedMediaController