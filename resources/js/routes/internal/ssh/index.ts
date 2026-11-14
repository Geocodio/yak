import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Internal\SshAuthorizedKeysController::__invoke
* @see app/Http/Controllers/Internal/SshAuthorizedKeysController.php:14
* @route '/internal/ssh/authorized-keys'
*/
export const authorizedKeys = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: authorizedKeys.url(options),
    method: 'post',
})

authorizedKeys.definition = {
    methods: ["post"],
    url: '/internal/ssh/authorized-keys',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SshAuthorizedKeysController::__invoke
* @see app/Http/Controllers/Internal/SshAuthorizedKeysController.php:14
* @route '/internal/ssh/authorized-keys'
*/
authorizedKeys.url = (options?: RouteQueryOptions) => {
    return authorizedKeys.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshAuthorizedKeysController::__invoke
* @see app/Http/Controllers/Internal/SshAuthorizedKeysController.php:14
* @route '/internal/ssh/authorized-keys'
*/
authorizedKeys.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: authorizedKeys.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Internal\SshListenersController::__invoke
* @see app/Http/Controllers/Internal/SshListenersController.php:14
* @route '/internal/ssh/listeners'
*/
export const listeners = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listeners.url(options),
    method: 'get',
})

listeners.definition = {
    methods: ["get","head"],
    url: '/internal/ssh/listeners',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Internal\SshListenersController::__invoke
* @see app/Http/Controllers/Internal/SshListenersController.php:14
* @route '/internal/ssh/listeners'
*/
listeners.url = (options?: RouteQueryOptions) => {
    return listeners.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshListenersController::__invoke
* @see app/Http/Controllers/Internal/SshListenersController.php:14
* @route '/internal/ssh/listeners'
*/
listeners.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: listeners.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Internal\SshListenersController::__invoke
* @see app/Http/Controllers/Internal/SshListenersController.php:14
* @route '/internal/ssh/listeners'
*/
listeners.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: listeners.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Internal\SshWakeController::__invoke
* @see app/Http/Controllers/Internal/SshWakeController.php:14
* @route '/internal/ssh/wake'
*/
export const wake = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: wake.url(options),
    method: 'post',
})

wake.definition = {
    methods: ["post"],
    url: '/internal/ssh/wake',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SshWakeController::__invoke
* @see app/Http/Controllers/Internal/SshWakeController.php:14
* @route '/internal/ssh/wake'
*/
wake.url = (options?: RouteQueryOptions) => {
    return wake.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshWakeController::__invoke
* @see app/Http/Controllers/Internal/SshWakeController.php:14
* @route '/internal/ssh/wake'
*/
wake.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: wake.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Internal\SshTerminalRedeemController::__invoke
* @see app/Http/Controllers/Internal/SshTerminalRedeemController.php:21
* @route '/internal/ssh/terminal/redeem'
*/
export const terminalRedeem = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: terminalRedeem.url(options),
    method: 'post',
})

terminalRedeem.definition = {
    methods: ["post"],
    url: '/internal/ssh/terminal/redeem',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SshTerminalRedeemController::__invoke
* @see app/Http/Controllers/Internal/SshTerminalRedeemController.php:21
* @route '/internal/ssh/terminal/redeem'
*/
terminalRedeem.url = (options?: RouteQueryOptions) => {
    return terminalRedeem.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshTerminalRedeemController::__invoke
* @see app/Http/Controllers/Internal/SshTerminalRedeemController.php:21
* @route '/internal/ssh/terminal/redeem'
*/
terminalRedeem.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: terminalRedeem.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Internal\SshTerminalCheckController::__invoke
* @see app/Http/Controllers/Internal/SshTerminalCheckController.php:15
* @route '/internal/ssh/terminal/check'
*/
export const terminalCheck = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: terminalCheck.url(options),
    method: 'post',
})

terminalCheck.definition = {
    methods: ["post"],
    url: '/internal/ssh/terminal/check',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SshTerminalCheckController::__invoke
* @see app/Http/Controllers/Internal/SshTerminalCheckController.php:15
* @route '/internal/ssh/terminal/check'
*/
terminalCheck.url = (options?: RouteQueryOptions) => {
    return terminalCheck.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshTerminalCheckController::__invoke
* @see app/Http/Controllers/Internal/SshTerminalCheckController.php:15
* @route '/internal/ssh/terminal/check'
*/
terminalCheck.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: terminalCheck.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Internal\SshActivityController::__invoke
* @see app/Http/Controllers/Internal/SshActivityController.php:17
* @route '/internal/ssh/activity'
*/
export const activity = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: activity.url(options),
    method: 'post',
})

activity.definition = {
    methods: ["post"],
    url: '/internal/ssh/activity',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Internal\SshActivityController::__invoke
* @see app/Http/Controllers/Internal/SshActivityController.php:17
* @route '/internal/ssh/activity'
*/
activity.url = (options?: RouteQueryOptions) => {
    return activity.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Internal\SshActivityController::__invoke
* @see app/Http/Controllers/Internal/SshActivityController.php:17
* @route '/internal/ssh/activity'
*/
activity.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: activity.url(options),
    method: 'post',
})

const ssh = {
    authorizedKeys: Object.assign(authorizedKeys, authorizedKeys),
    listeners: Object.assign(listeners, listeners),
    wake: Object.assign(wake, wake),
    terminalRedeem: Object.assign(terminalRedeem, terminalRedeem),
    terminalCheck: Object.assign(terminalCheck, terminalCheck),
    activity: Object.assign(activity, activity),
}

export default ssh