import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
import devices from './devices'
/**
* @see \App\Http\Controllers\Settings\NotificationController::preferences
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/NotificationController.php:24
* @route '/settings/notifications'
*/
export const preferences = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: preferences.url(options),
    method: 'put',
})

preferences.definition = {
    methods: ["put"],
    url: '/settings/notifications',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Settings\NotificationController::preferences
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/NotificationController.php:24
* @route '/settings/notifications'
*/
preferences.url = (options?: RouteQueryOptions) => {
    return preferences.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\NotificationController::preferences
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Settings/NotificationController.php:24
* @route '/settings/notifications'
*/
preferences.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: preferences.url(options),
    method: 'put',
})

const notifications = {
    preferences: Object.assign(preferences, preferences),
    devices: Object.assign(devices, devices),
}

export default notifications