import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\AppEmbed\SessionController::__invoke
* @see app/Http/Controllers/AppEmbed/SessionController.php:29
* @route '/app/embed/session'
*/
const SessionController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SessionController.url(options),
    method: 'post',
})

SessionController.definition = {
    methods: ["post"],
    url: '/app/embed/session',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\AppEmbed\SessionController::__invoke
* @see app/Http/Controllers/AppEmbed/SessionController.php:29
* @route '/app/embed/session'
*/
SessionController.url = (options?: RouteQueryOptions) => {
    return SessionController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AppEmbed\SessionController::__invoke
* @see app/Http/Controllers/AppEmbed/SessionController.php:29
* @route '/app/embed/session'
*/
SessionController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: SessionController.url(options),
    method: 'post',
})

export default SessionController