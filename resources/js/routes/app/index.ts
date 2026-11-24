import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
import embed from './embed'
import device from './device'
import threads from './threads'
import broadcasting from './broadcasting'
import observations from './observations'
import tasks from './tasks'
import pushTokens from './push-tokens'
import notifications from './notifications'
import liveActivities from './live-activities'
import webSession from './web-session'
import media from './media'
/**
* @see \App\Http\Controllers\Api\App\InstanceController::__invoke
* @see app/Http/Controllers/Api/App/InstanceController.php:13
* @route '/api/app/v1/instance'
*/
export const instance = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: instance.url(options),
    method: 'get',
})

instance.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/instance',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\InstanceController::__invoke
* @see app/Http/Controllers/Api/App/InstanceController.php:13
* @route '/api/app/v1/instance'
*/
instance.url = (options?: RouteQueryOptions) => {
    return instance.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\InstanceController::__invoke
* @see app/Http/Controllers/Api/App/InstanceController.php:13
* @route '/api/app/v1/instance'
*/
instance.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: instance.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\InstanceController::__invoke
* @see app/Http/Controllers/Api/App/InstanceController.php:13
* @route '/api/app/v1/instance'
*/
instance.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: instance.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\MeController::__invoke
* @see app/Http/Controllers/Api/App/MeController.php:13
* @route '/api/app/v1/me'
*/
export const me = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: me.url(options),
    method: 'get',
})

me.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/me',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\MeController::__invoke
* @see app/Http/Controllers/Api/App/MeController.php:13
* @route '/api/app/v1/me'
*/
me.url = (options?: RouteQueryOptions) => {
    return me.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\MeController::__invoke
* @see app/Http/Controllers/Api/App/MeController.php:13
* @route '/api/app/v1/me'
*/
me.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: me.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\MeController::__invoke
* @see app/Http/Controllers/Api/App/MeController.php:13
* @route '/api/app/v1/me'
*/
me.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: me.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\SearchController::__invoke
* @see app/Http/Controllers/Api/App/SearchController.php:13
* @route '/api/app/v1/search'
*/
export const search = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: search.url(options),
    method: 'get',
})

search.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/search',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\SearchController::__invoke
* @see app/Http/Controllers/Api/App/SearchController.php:13
* @route '/api/app/v1/search'
*/
search.url = (options?: RouteQueryOptions) => {
    return search.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\SearchController::__invoke
* @see app/Http/Controllers/Api/App/SearchController.php:13
* @route '/api/app/v1/search'
*/
search.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: search.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\SearchController::__invoke
* @see app/Http/Controllers/Api/App/SearchController.php:13
* @route '/api/app/v1/search'
*/
search.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: search.url(options),
    method: 'head',
})

const app = {
    embed: Object.assign(embed, embed),
    instance: Object.assign(instance, instance),
    device: Object.assign(device, device),
    me: Object.assign(me, me),
    threads: Object.assign(threads, threads),
    broadcasting: Object.assign(broadcasting, broadcasting),
    observations: Object.assign(observations, observations),
    tasks: Object.assign(tasks, tasks),
    pushTokens: Object.assign(pushTokens, pushTokens),
    notifications: Object.assign(notifications, notifications),
    liveActivities: Object.assign(liveActivities, liveActivities),
    search: Object.assign(search, search),
    webSession: Object.assign(webSession, webSession),
    media: Object.assign(media, media),
}

export default app