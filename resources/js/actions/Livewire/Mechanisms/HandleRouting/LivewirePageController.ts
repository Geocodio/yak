import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/tasks/{task}'
*/
const LivewirePageControllera9210b42b2fb5ac9933186a51e3242ee = (args: { task: string | number } | [task: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllera9210b42b2fb5ac9933186a51e3242ee.url(args, options),
    method: 'get',
})

LivewirePageControllera9210b42b2fb5ac9933186a51e3242ee.definition = {
    methods: ["get","head"],
    url: '/tasks/{task}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/tasks/{task}'
*/
LivewirePageControllera9210b42b2fb5ac9933186a51e3242ee.url = (args: { task: string | number } | [task: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: args.task,
    }

    return LivewirePageControllera9210b42b2fb5ac9933186a51e3242ee.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/tasks/{task}'
*/
LivewirePageControllera9210b42b2fb5ac9933186a51e3242ee.get = (args: { task: string | number } | [task: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllera9210b42b2fb5ac9933186a51e3242ee.url(args, options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/tasks/{task}'
*/
LivewirePageControllera9210b42b2fb5ac9933186a51e3242ee.head = (args: { task: string | number } | [task: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageControllera9210b42b2fb5ac9933186a51e3242ee.url(args, options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/costs'
*/
const LivewirePageController2c872e28f212301e5f28e254d61efb55 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController2c872e28f212301e5f28e254d61efb55.url(options),
    method: 'get',
})

LivewirePageController2c872e28f212301e5f28e254d61efb55.definition = {
    methods: ["get","head"],
    url: '/costs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/costs'
*/
LivewirePageController2c872e28f212301e5f28e254d61efb55.url = (options?: RouteQueryOptions) => {
    return LivewirePageController2c872e28f212301e5f28e254d61efb55.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/costs'
*/
LivewirePageController2c872e28f212301e5f28e254d61efb55.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController2c872e28f212301e5f28e254d61efb55.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/costs'
*/
LivewirePageController2c872e28f212301e5f28e254d61efb55.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageController2c872e28f212301e5f28e254d61efb55.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos'
*/
const LivewirePageControllerf25a416d0aad3f4e54d302d76054838b = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerf25a416d0aad3f4e54d302d76054838b.url(options),
    method: 'get',
})

LivewirePageControllerf25a416d0aad3f4e54d302d76054838b.definition = {
    methods: ["get","head"],
    url: '/repos',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos'
*/
LivewirePageControllerf25a416d0aad3f4e54d302d76054838b.url = (options?: RouteQueryOptions) => {
    return LivewirePageControllerf25a416d0aad3f4e54d302d76054838b.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos'
*/
LivewirePageControllerf25a416d0aad3f4e54d302d76054838b.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerf25a416d0aad3f4e54d302d76054838b.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos'
*/
LivewirePageControllerf25a416d0aad3f4e54d302d76054838b.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageControllerf25a416d0aad3f4e54d302d76054838b.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos/create'
*/
const LivewirePageController5240f3888f250125bd5237c5ce4bbaed = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController5240f3888f250125bd5237c5ce4bbaed.url(options),
    method: 'get',
})

LivewirePageController5240f3888f250125bd5237c5ce4bbaed.definition = {
    methods: ["get","head"],
    url: '/repos/create',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos/create'
*/
LivewirePageController5240f3888f250125bd5237c5ce4bbaed.url = (options?: RouteQueryOptions) => {
    return LivewirePageController5240f3888f250125bd5237c5ce4bbaed.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos/create'
*/
LivewirePageController5240f3888f250125bd5237c5ce4bbaed.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController5240f3888f250125bd5237c5ce4bbaed.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos/create'
*/
LivewirePageController5240f3888f250125bd5237c5ce4bbaed.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageController5240f3888f250125bd5237c5ce4bbaed.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos/{repository}/edit'
*/
const LivewirePageControllera507b3d8ec965bbeae4889dd6f3b2f7a = (args: { repository: string | number } | [repository: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllera507b3d8ec965bbeae4889dd6f3b2f7a.url(args, options),
    method: 'get',
})

LivewirePageControllera507b3d8ec965bbeae4889dd6f3b2f7a.definition = {
    methods: ["get","head"],
    url: '/repos/{repository}/edit',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos/{repository}/edit'
*/
LivewirePageControllera507b3d8ec965bbeae4889dd6f3b2f7a.url = (args: { repository: string | number } | [repository: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { repository: args }
    }

    if (Array.isArray(args)) {
        args = {
            repository: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repository: args.repository,
    }

    return LivewirePageControllera507b3d8ec965bbeae4889dd6f3b2f7a.definition.url
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos/{repository}/edit'
*/
LivewirePageControllera507b3d8ec965bbeae4889dd6f3b2f7a.get = (args: { repository: string | number } | [repository: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllera507b3d8ec965bbeae4889dd6f3b2f7a.url(args, options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/repos/{repository}/edit'
*/
LivewirePageControllera507b3d8ec965bbeae4889dd6f3b2f7a.head = (args: { repository: string | number } | [repository: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageControllera507b3d8ec965bbeae4889dd6f3b2f7a.url(args, options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/health'
*/
const LivewirePageControllerf5005a7fa854a42af9f9f8f93fe8f2c2 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerf5005a7fa854a42af9f9f8f93fe8f2c2.url(options),
    method: 'get',
})

LivewirePageControllerf5005a7fa854a42af9f9f8f93fe8f2c2.definition = {
    methods: ["get","head"],
    url: '/health',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/health'
*/
LivewirePageControllerf5005a7fa854a42af9f9f8f93fe8f2c2.url = (options?: RouteQueryOptions) => {
    return LivewirePageControllerf5005a7fa854a42af9f9f8f93fe8f2c2.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/health'
*/
LivewirePageControllerf5005a7fa854a42af9f9f8f93fe8f2c2.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerf5005a7fa854a42af9f9f8f93fe8f2c2.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/health'
*/
LivewirePageControllerf5005a7fa854a42af9f9f8f93fe8f2c2.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageControllerf5005a7fa854a42af9f9f8f93fe8f2c2.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/channels'
*/
const LivewirePageController100b47a7e69980d7fc8f5594d43bb241 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController100b47a7e69980d7fc8f5594d43bb241.url(options),
    method: 'get',
})

LivewirePageController100b47a7e69980d7fc8f5594d43bb241.definition = {
    methods: ["get","head"],
    url: '/channels',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/channels'
*/
LivewirePageController100b47a7e69980d7fc8f5594d43bb241.url = (options?: RouteQueryOptions) => {
    return LivewirePageController100b47a7e69980d7fc8f5594d43bb241.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/channels'
*/
LivewirePageController100b47a7e69980d7fc8f5594d43bb241.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController100b47a7e69980d7fc8f5594d43bb241.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/channels'
*/
LivewirePageController100b47a7e69980d7fc8f5594d43bb241.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageController100b47a7e69980d7fc8f5594d43bb241.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/prompts'
*/
const LivewirePageControllere9713578cbcfc104921da2d1f0e9be0b = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllere9713578cbcfc104921da2d1f0e9be0b.url(options),
    method: 'get',
})

LivewirePageControllere9713578cbcfc104921da2d1f0e9be0b.definition = {
    methods: ["get","head"],
    url: '/prompts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/prompts'
*/
LivewirePageControllere9713578cbcfc104921da2d1f0e9be0b.url = (options?: RouteQueryOptions) => {
    return LivewirePageControllere9713578cbcfc104921da2d1f0e9be0b.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/prompts'
*/
LivewirePageControllere9713578cbcfc104921da2d1f0e9be0b.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllere9713578cbcfc104921da2d1f0e9be0b.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/prompts'
*/
LivewirePageControllere9713578cbcfc104921da2d1f0e9be0b.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageControllere9713578cbcfc104921da2d1f0e9be0b.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/skills'
*/
const LivewirePageController959dc9a14e4c2e5eec51ea9e4fbd8146 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController959dc9a14e4c2e5eec51ea9e4fbd8146.url(options),
    method: 'get',
})

LivewirePageController959dc9a14e4c2e5eec51ea9e4fbd8146.definition = {
    methods: ["get","head"],
    url: '/skills',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/skills'
*/
LivewirePageController959dc9a14e4c2e5eec51ea9e4fbd8146.url = (options?: RouteQueryOptions) => {
    return LivewirePageController959dc9a14e4c2e5eec51ea9e4fbd8146.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/skills'
*/
LivewirePageController959dc9a14e4c2e5eec51ea9e4fbd8146.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController959dc9a14e4c2e5eec51ea9e4fbd8146.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/skills'
*/
LivewirePageController959dc9a14e4c2e5eec51ea9e4fbd8146.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageController959dc9a14e4c2e5eec51ea9e4fbd8146.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews'
*/
const LivewirePageControllerd386bb3fdb6980c2076ee05a0d1f99d5 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerd386bb3fdb6980c2076ee05a0d1f99d5.url(options),
    method: 'get',
})

LivewirePageControllerd386bb3fdb6980c2076ee05a0d1f99d5.definition = {
    methods: ["get","head"],
    url: '/pr-reviews',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews'
*/
LivewirePageControllerd386bb3fdb6980c2076ee05a0d1f99d5.url = (options?: RouteQueryOptions) => {
    return LivewirePageControllerd386bb3fdb6980c2076ee05a0d1f99d5.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews'
*/
LivewirePageControllerd386bb3fdb6980c2076ee05a0d1f99d5.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerd386bb3fdb6980c2076ee05a0d1f99d5.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews'
*/
LivewirePageControllerd386bb3fdb6980c2076ee05a0d1f99d5.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageControllerd386bb3fdb6980c2076ee05a0d1f99d5.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
const LivewirePageControllerefbb4b9273f03868efa251b52ecf8ff7 = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerefbb4b9273f03868efa251b52ecf8ff7.url(args, options),
    method: 'get',
})

LivewirePageControllerefbb4b9273f03868efa251b52ecf8ff7.definition = {
    methods: ["get","head"],
    url: '/pr-reviews/for/{repoSlug}/{prNumber}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
LivewirePageControllerefbb4b9273f03868efa251b52ecf8ff7.url = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repoSlug: args[0],
            prNumber: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repoSlug: args.repoSlug,
        prNumber: args.prNumber,
    }

    return LivewirePageControllerefbb4b9273f03868efa251b52ecf8ff7.definition.url
            .replace('{repoSlug}', parsedArgs.repoSlug.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
LivewirePageControllerefbb4b9273f03868efa251b52ecf8ff7.get = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerefbb4b9273f03868efa251b52ecf8ff7.url(args, options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
LivewirePageControllerefbb4b9273f03868efa251b52ecf8ff7.head = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageControllerefbb4b9273f03868efa251b52ecf8ff7.url(args, options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments'
*/
const LivewirePageController4a179efa3e1327fe1fa966165df8127c = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController4a179efa3e1327fe1fa966165df8127c.url(options),
    method: 'get',
})

LivewirePageController4a179efa3e1327fe1fa966165df8127c.definition = {
    methods: ["get","head"],
    url: '/deployments',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments'
*/
LivewirePageController4a179efa3e1327fe1fa966165df8127c.url = (options?: RouteQueryOptions) => {
    return LivewirePageController4a179efa3e1327fe1fa966165df8127c.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments'
*/
LivewirePageController4a179efa3e1327fe1fa966165df8127c.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController4a179efa3e1327fe1fa966165df8127c.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments'
*/
LivewirePageController4a179efa3e1327fe1fa966165df8127c.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageController4a179efa3e1327fe1fa966165df8127c.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments/{deployment}'
*/
const LivewirePageController5479acbafef394b07eb8e9477867ef33 = (args: { deployment: string | number } | [deployment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController5479acbafef394b07eb8e9477867ef33.url(args, options),
    method: 'get',
})

LivewirePageController5479acbafef394b07eb8e9477867ef33.definition = {
    methods: ["get","head"],
    url: '/deployments/{deployment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments/{deployment}'
*/
LivewirePageController5479acbafef394b07eb8e9477867ef33.url = (args: { deployment: string | number } | [deployment: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { deployment: args }
    }

    if (Array.isArray(args)) {
        args = {
            deployment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        deployment: args.deployment,
    }

    return LivewirePageController5479acbafef394b07eb8e9477867ef33.definition.url
            .replace('{deployment}', parsedArgs.deployment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments/{deployment}'
*/
LivewirePageController5479acbafef394b07eb8e9477867ef33.get = (args: { deployment: string | number } | [deployment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController5479acbafef394b07eb8e9477867ef33.url(args, options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/deployments/{deployment}'
*/
LivewirePageController5479acbafef394b07eb8e9477867ef33.head = (args: { deployment: string | number } | [deployment: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageController5479acbafef394b07eb8e9477867ef33.url(args, options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/profile'
*/
const LivewirePageControllerfc6874003af373efc88e5e18eecd9c17 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerfc6874003af373efc88e5e18eecd9c17.url(options),
    method: 'get',
})

LivewirePageControllerfc6874003af373efc88e5e18eecd9c17.definition = {
    methods: ["get","head"],
    url: '/settings/profile',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/profile'
*/
LivewirePageControllerfc6874003af373efc88e5e18eecd9c17.url = (options?: RouteQueryOptions) => {
    return LivewirePageControllerfc6874003af373efc88e5e18eecd9c17.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/profile'
*/
LivewirePageControllerfc6874003af373efc88e5e18eecd9c17.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerfc6874003af373efc88e5e18eecd9c17.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/profile'
*/
LivewirePageControllerfc6874003af373efc88e5e18eecd9c17.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageControllerfc6874003af373efc88e5e18eecd9c17.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/linear'
*/
const LivewirePageControllerfb19713afe56a42dfe25317208a7b263 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerfb19713afe56a42dfe25317208a7b263.url(options),
    method: 'get',
})

LivewirePageControllerfb19713afe56a42dfe25317208a7b263.definition = {
    methods: ["get","head"],
    url: '/settings/linear',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/linear'
*/
LivewirePageControllerfb19713afe56a42dfe25317208a7b263.url = (options?: RouteQueryOptions) => {
    return LivewirePageControllerfb19713afe56a42dfe25317208a7b263.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/linear'
*/
LivewirePageControllerfb19713afe56a42dfe25317208a7b263.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageControllerfb19713afe56a42dfe25317208a7b263.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/linear'
*/
LivewirePageControllerfb19713afe56a42dfe25317208a7b263.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageControllerfb19713afe56a42dfe25317208a7b263.url(options),
    method: 'head',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/video'
*/
const LivewirePageController8e94f99b226141ef399b6115395002b6 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController8e94f99b226141ef399b6115395002b6.url(options),
    method: 'get',
})

LivewirePageController8e94f99b226141ef399b6115395002b6.definition = {
    methods: ["get","head"],
    url: '/settings/video',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/video'
*/
LivewirePageController8e94f99b226141ef399b6115395002b6.url = (options?: RouteQueryOptions) => {
    return LivewirePageController8e94f99b226141ef399b6115395002b6.definition.url + queryParams(options)
}

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/video'
*/
LivewirePageController8e94f99b226141ef399b6115395002b6.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: LivewirePageController8e94f99b226141ef399b6115395002b6.url(options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/settings/video'
*/
LivewirePageController8e94f99b226141ef399b6115395002b6.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: LivewirePageController8e94f99b226141ef399b6115395002b6.url(options),
    method: 'head',
})

/**
* Multiple routes resolve to \Livewire\Mechanisms\HandleRouting\LivewirePageController::LivewirePageController, so this export is a
* dictionary keyed by URI rather than a callable. Call a specific route with `LivewirePageController['<uri>'](...)`,
* or import the route by name from your generated `routes/` directory.
*/
const LivewirePageController = {
    '/tasks/{task}': LivewirePageControllera9210b42b2fb5ac9933186a51e3242ee,
    '/costs': LivewirePageController2c872e28f212301e5f28e254d61efb55,
    '/repos': LivewirePageControllerf25a416d0aad3f4e54d302d76054838b,
    '/repos/create': LivewirePageController5240f3888f250125bd5237c5ce4bbaed,
    '/repos/{repository}/edit': LivewirePageControllera507b3d8ec965bbeae4889dd6f3b2f7a,
    '/health': LivewirePageControllerf5005a7fa854a42af9f9f8f93fe8f2c2,
    '/channels': LivewirePageController100b47a7e69980d7fc8f5594d43bb241,
    '/prompts': LivewirePageControllere9713578cbcfc104921da2d1f0e9be0b,
    '/skills': LivewirePageController959dc9a14e4c2e5eec51ea9e4fbd8146,
    '/pr-reviews': LivewirePageControllerd386bb3fdb6980c2076ee05a0d1f99d5,
    '/pr-reviews/for/{repoSlug}/{prNumber}': LivewirePageControllerefbb4b9273f03868efa251b52ecf8ff7,
    '/deployments': LivewirePageController4a179efa3e1327fe1fa966165df8127c,
    '/deployments/{deployment}': LivewirePageController5479acbafef394b07eb8e9477867ef33,
    '/settings/profile': LivewirePageControllerfc6874003af373efc88e5e18eecd9c17,
    '/settings/linear': LivewirePageControllerfb19713afe56a42dfe25317208a7b263,
    '/settings/video': LivewirePageController8e94f99b226141ef399b6115395002b6,
}

export default LivewirePageController