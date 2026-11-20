import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
import threads from './threads'
/**
* @see \App\Http\Controllers\AppEmbed\SessionController::__invoke
* @see app/Http/Controllers/AppEmbed/SessionController.php:29
* @route '/app/embed/session'
*/
export const session = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: session.url(options),
    method: 'post',
})

session.definition = {
    methods: ["post"],
    url: '/app/embed/session',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\AppEmbed\SessionController::__invoke
* @see app/Http/Controllers/AppEmbed/SessionController.php:29
* @route '/app/embed/session'
*/
session.url = (options?: RouteQueryOptions) => {
    return session.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AppEmbed\SessionController::__invoke
* @see app/Http/Controllers/AppEmbed/SessionController.php:29
* @route '/app/embed/session'
*/
session.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: session.url(options),
    method: 'post',
})

const embed = {
    session: Object.assign(session, session),
    threads: Object.assign(threads, threads),
}

export default embed