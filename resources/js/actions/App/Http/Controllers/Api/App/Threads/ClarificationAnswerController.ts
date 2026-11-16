import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\ClarificationAnswerController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ClarificationAnswerController.php:14
* @route '/api/app/v1/threads/{thread}/clarification-answers'
*/
const ClarificationAnswerController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ClarificationAnswerController.url(args, options),
    method: 'post',
})

ClarificationAnswerController.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/clarification-answers',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ClarificationAnswerController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ClarificationAnswerController.php:14
* @route '/api/app/v1/threads/{thread}/clarification-answers'
*/
ClarificationAnswerController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return ClarificationAnswerController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ClarificationAnswerController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ClarificationAnswerController.php:14
* @route '/api/app/v1/threads/{thread}/clarification-answers'
*/
ClarificationAnswerController.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ClarificationAnswerController.url(args, options),
    method: 'post',
})

export default ClarificationAnswerController