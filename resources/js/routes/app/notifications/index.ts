import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Push\NotificationPreferenceController::show
* @see app/Http/Controllers/Api/App/Push/NotificationPreferenceController.php:14
* @route '/api/app/v1/notifications'
*/
export const show = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/notifications',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Push\NotificationPreferenceController::show
* @see app/Http/Controllers/Api/App/Push/NotificationPreferenceController.php:14
* @route '/api/app/v1/notifications'
*/
show.url = (options?: RouteQueryOptions) => {
    return show.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Push\NotificationPreferenceController::show
* @see app/Http/Controllers/Api/App/Push/NotificationPreferenceController.php:14
* @route '/api/app/v1/notifications'
*/
show.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Push\NotificationPreferenceController::show
* @see app/Http/Controllers/Api/App/Push/NotificationPreferenceController.php:14
* @route '/api/app/v1/notifications'
*/
show.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Push\NotificationPreferenceController::update
* @see app/Http/Controllers/Api/App/Push/NotificationPreferenceController.php:22
* @route '/api/app/v1/notifications'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/api/app/v1/notifications',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Api\App\Push\NotificationPreferenceController::update
* @see app/Http/Controllers/Api/App/Push/NotificationPreferenceController.php:22
* @route '/api/app/v1/notifications'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Push\NotificationPreferenceController::update
* @see app/Http/Controllers/Api/App/Push/NotificationPreferenceController.php:22
* @route '/api/app/v1/notifications'
*/
update.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(options),
    method: 'put',
})

const notifications = {
    show: Object.assign(show, show),
    update: Object.assign(update, update),
}

export default notifications