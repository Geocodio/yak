import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
import claudeAccounts97a877 from './claude-accounts'
import devices95ecd0 from './devices'
import github from './github'
import linearCc9558 from './linear'
import video6ca361 from './video'
/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::claudeAccounts
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:31
* @route '/settings/claude-accounts'
*/
export const claudeAccounts = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: claudeAccounts.url(options),
    method: 'get',
})

claudeAccounts.definition = {
    methods: ["get","head"],
    url: '/settings/claude-accounts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::claudeAccounts
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:31
* @route '/settings/claude-accounts'
*/
claudeAccounts.url = (options?: RouteQueryOptions) => {
    return claudeAccounts.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::claudeAccounts
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:31
* @route '/settings/claude-accounts'
*/
claudeAccounts.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: claudeAccounts.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ClaudeAccountController::claudeAccounts
* @see app/Http/Controllers/Settings/ClaudeAccountController.php:31
* @route '/settings/claude-accounts'
*/
claudeAccounts.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: claudeAccounts.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\DeviceController::devices
* @see app/Http/Controllers/Settings/DeviceController.php:16
* @route '/settings/devices'
*/
export const devices = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: devices.url(options),
    method: 'get',
})

devices.definition = {
    methods: ["get","head"],
    url: '/settings/devices',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\DeviceController::devices
* @see app/Http/Controllers/Settings/DeviceController.php:16
* @route '/settings/devices'
*/
devices.url = (options?: RouteQueryOptions) => {
    return devices.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\DeviceController::devices
* @see app/Http/Controllers/Settings/DeviceController.php:16
* @route '/settings/devices'
*/
devices.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: devices.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\DeviceController::devices
* @see app/Http/Controllers/Settings/DeviceController.php:16
* @route '/settings/devices'
*/
devices.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: devices.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::linear
* @see app/Http/Controllers/Settings/LinearConnectionController.php:16
* @route '/settings/linear'
*/
export const linear = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: linear.url(options),
    method: 'get',
})

linear.definition = {
    methods: ["get","head"],
    url: '/settings/linear',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::linear
* @see app/Http/Controllers/Settings/LinearConnectionController.php:16
* @route '/settings/linear'
*/
linear.url = (options?: RouteQueryOptions) => {
    return linear.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::linear
* @see app/Http/Controllers/Settings/LinearConnectionController.php:16
* @route '/settings/linear'
*/
linear.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: linear.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\LinearConnectionController::linear
* @see app/Http/Controllers/Settings/LinearConnectionController.php:16
* @route '/settings/linear'
*/
linear.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: linear.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::video
* @see app/Http/Controllers/Settings/VideoThemeController.php:21
* @route '/settings/video'
*/
export const video = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: video.url(options),
    method: 'get',
})

video.definition = {
    methods: ["get","head"],
    url: '/settings/video',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::video
* @see app/Http/Controllers/Settings/VideoThemeController.php:21
* @route '/settings/video'
*/
video.url = (options?: RouteQueryOptions) => {
    return video.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::video
* @see app/Http/Controllers/Settings/VideoThemeController.php:21
* @route '/settings/video'
*/
video.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: video.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\VideoThemeController::video
* @see app/Http/Controllers/Settings/VideoThemeController.php:21
* @route '/settings/video'
*/
video.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: video.url(options),
    method: 'head',
})

const settings = {
    claudeAccounts: Object.assign(claudeAccounts, claudeAccounts97a877),
    devices: Object.assign(devices, devices95ecd0),
    github: Object.assign(github, github),
    linear: Object.assign(linear, linearCc9558),
    video: Object.assign(video, video6ca361),
}

export default settings