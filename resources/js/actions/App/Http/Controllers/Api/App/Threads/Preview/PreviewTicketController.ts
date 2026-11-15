import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewTicketController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewTicketController.php:21
* @route '/api/app/v1/threads/{thread}/preview/ticket'
*/
const PreviewTicketController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: PreviewTicketController.url(args, options),
    method: 'post',
})

PreviewTicketController.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/preview/ticket',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewTicketController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewTicketController.php:21
* @route '/api/app/v1/threads/{thread}/preview/ticket'
*/
PreviewTicketController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return PreviewTicketController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\Preview\PreviewTicketController::__invoke
* @see app/Http/Controllers/Api/App/Threads/Preview/PreviewTicketController.php:21
* @route '/api/app/v1/threads/{thread}/preview/ticket'
*/
PreviewTicketController.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: PreviewTicketController.url(args, options),
    method: 'post',
})

export default PreviewTicketController