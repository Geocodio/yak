import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\SkillController::index
* @see app/Http/Controllers/SkillController.php:28
* @route '/skills'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/skills',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SkillController::index
* @see app/Http/Controllers/SkillController.php:28
* @route '/skills'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SkillController::index
* @see app/Http/Controllers/SkillController.php:28
* @route '/skills'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SkillController::index
* @see app/Http/Controllers/SkillController.php:28
* @route '/skills'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\SkillController::store
* @see app/Http/Controllers/SkillController.php:50
* @route '/skills'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/skills',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SkillController::store
* @see app/Http/Controllers/SkillController.php:50
* @route '/skills'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SkillController::store
* @see app/Http/Controllers/SkillController.php:50
* @route '/skills'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SkillController::update
* @see app/Http/Controllers/SkillController.php:70
* @route '/skills/{name}'
*/
export const update = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/skills/{name}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\SkillController::update
* @see app/Http/Controllers/SkillController.php:70
* @route '/skills/{name}'
*/
update.url = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { name: args }
    }

    if (Array.isArray(args)) {
        args = {
            name: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        name: args.name,
    }

    return update.definition.url
            .replace('{name}', parsedArgs.name.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SkillController::update
* @see app/Http/Controllers/SkillController.php:70
* @route '/skills/{name}'
*/
update.patch = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\SkillController::upgrade
* @see app/Http/Controllers/SkillController.php:94
* @route '/skills/{name}/update'
*/
export const upgrade = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: upgrade.url(args, options),
    method: 'post',
})

upgrade.definition = {
    methods: ["post"],
    url: '/skills/{name}/update',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SkillController::upgrade
* @see app/Http/Controllers/SkillController.php:94
* @route '/skills/{name}/update'
*/
upgrade.url = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { name: args }
    }

    if (Array.isArray(args)) {
        args = {
            name: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        name: args.name,
    }

    return upgrade.definition.url
            .replace('{name}', parsedArgs.name.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SkillController::upgrade
* @see app/Http/Controllers/SkillController.php:94
* @route '/skills/{name}/update'
*/
upgrade.post = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: upgrade.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SkillController::destroy
* @see app/Http/Controllers/SkillController.php:85
* @route '/skills/{name}'
*/
export const destroy = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/skills/{name}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\SkillController::destroy
* @see app/Http/Controllers/SkillController.php:85
* @route '/skills/{name}'
*/
destroy.url = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { name: args }
    }

    if (Array.isArray(args)) {
        args = {
            name: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        name: args.name,
    }

    return destroy.definition.url
            .replace('{name}', parsedArgs.name.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SkillController::destroy
* @see app/Http/Controllers/SkillController.php:85
* @route '/skills/{name}'
*/
destroy.delete = (args: { name: string | number } | [name: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const SkillController = { index, store, update, upgrade, destroy }

export default SkillController