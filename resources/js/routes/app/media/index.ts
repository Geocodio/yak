import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::taskAttachments
* @see app/Http/Controllers/Api/App/SignedMediaController.php:23
* @route '/app-media/task-attachments/{attachment}'
*/
export const taskAttachments = (args: { attachment: string | number } | [attachment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: taskAttachments.url(args, options),
    method: 'get',
})

taskAttachments.definition = {
    methods: ["get","head"],
    url: '/app-media/task-attachments/{attachment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::taskAttachments
* @see app/Http/Controllers/Api/App/SignedMediaController.php:23
* @route '/app-media/task-attachments/{attachment}'
*/
taskAttachments.url = (args: { attachment: string | number } | [attachment: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return taskAttachments.definition.url
            .replace('{attachment}', parsedArgs.attachment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::taskAttachments
* @see app/Http/Controllers/Api/App/SignedMediaController.php:23
* @route '/app-media/task-attachments/{attachment}'
*/
taskAttachments.get = (args: { attachment: string | number } | [attachment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: taskAttachments.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::taskAttachments
* @see app/Http/Controllers/Api/App/SignedMediaController.php:23
* @route '/app-media/task-attachments/{attachment}'
*/
taskAttachments.head = (args: { attachment: string | number } | [attachment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: taskAttachments.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::prImages
* @see app/Http/Controllers/Api/App/SignedMediaController.php:28
* @route '/app-media/tasks/{task}/pr-images/{asset}'
*/
export const prImages = (args: { task: string | number, asset: string | number } | [task: string | number, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: prImages.url(args, options),
    method: 'get',
})

prImages.definition = {
    methods: ["get","head"],
    url: '/app-media/tasks/{task}/pr-images/{asset}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::prImages
* @see app/Http/Controllers/Api/App/SignedMediaController.php:28
* @route '/app-media/tasks/{task}/pr-images/{asset}'
*/
prImages.url = (args: { task: string | number, asset: string | number } | [task: string | number, asset: string | number ], options?: RouteQueryOptions) => {
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

    return prImages.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace('{asset}', parsedArgs.asset.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::prImages
* @see app/Http/Controllers/Api/App/SignedMediaController.php:28
* @route '/app-media/tasks/{task}/pr-images/{asset}'
*/
prImages.get = (args: { task: string | number, asset: string | number } | [task: string | number, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: prImages.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\SignedMediaController::prImages
* @see app/Http/Controllers/Api/App/SignedMediaController.php:28
* @route '/app-media/tasks/{task}/pr-images/{asset}'
*/
prImages.head = (args: { task: string | number, asset: string | number } | [task: string | number, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: prImages.url(args, options),
    method: 'head',
})

const media = {
    taskAttachments: Object.assign(taskAttachments, taskAttachments),
    prImages: Object.assign(prImages, prImages),
}

export default media