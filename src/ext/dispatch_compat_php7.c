#include "php.h"
#if PHP_VERSION_ID >= 70000

#include <Zend/zend_exceptions.h>

#include <ext/spl/spl_exceptions.h>

#include "ddtrace.h"
#include "debug.h"
#include "dispatch.h"
#include "dispatch_compat.h"

ZEND_EXTERN_MODULE_GLOBALS(signalfx_tracing)

void ddtrace_setup_fcall(zend_execute_data *execute_data, zend_fcall_info *fci, zval **result) {
    fci->param_count = ZEND_CALL_NUM_ARGS(execute_data);
    fci->params = ZEND_CALL_ARG(execute_data, 1);
    fci->retval = *result;
}

zend_function *ddtrace_function_get(const HashTable *table, zval *name) {
    if (Z_TYPE_P(name) != IS_STRING) {
        return NULL;
    }

    zend_string *to_free = NULL, *key = Z_STR_P(name);
    if (!ddtrace_is_all_lower(key)) {
        key = zend_string_tolower(key);
        to_free = key;
    }

    zend_function *ptr = zend_hash_find_ptr(table, key);

    if (to_free) {
        zend_string_release(to_free);
    }
    return ptr;
}

void ddtrace_dispatch_free_owned_data(ddtrace_dispatch_t *dispatch) {
    zval_ptr_dtor(&dispatch->function_name);
    zval_ptr_dtor(&dispatch->callable);
}

void ddtrace_class_lookup_release_compat(zval *zv) {
    DD_PRINTF("freeing %p", (void *)zv);
    ddtrace_dispatch_t *dispatch = Z_PTR_P(zv);
    ddtrace_class_lookup_release(dispatch);
}

HashTable *ddtrace_new_class_lookup(zval *class_name) {
    HashTable *class_lookup;

    ALLOC_HASHTABLE(class_lookup);
    zend_hash_init(class_lookup, 8, NULL, ddtrace_class_lookup_release_compat, 0);
    zend_hash_update_ptr(SIGNALFX_TRACING_G(class_lookup), Z_STR_P(class_name), class_lookup);

    return class_lookup;
}

zend_bool ddtrace_dispatch_store(HashTable *lookup, ddtrace_dispatch_t *dispatch_orig) {
#if PHP_VERSION_ID >= 70300
    ddtrace_dispatch_t *dispatch = pemalloc(sizeof(ddtrace_dispatch_t), lookup->u.flags & IS_ARRAY_PERSISTENT);
#else
    ddtrace_dispatch_t *dispatch = pemalloc(sizeof(ddtrace_dispatch_t), lookup->u.flags & HASH_FLAG_PERSISTENT);
#endif

    memcpy(dispatch, dispatch_orig, sizeof(ddtrace_dispatch_t));
    ddtrace_class_lookup_acquire(dispatch);
    return zend_hash_update_ptr(lookup, Z_STR(dispatch->function_name), dispatch) != NULL;
}

void ddtrace_forward_call(zend_execute_data *execute_data, zval *return_value TSRMLS_DC) {
    zval fname, retval;
    zend_fcall_info fci = {0};
    zend_fcall_info_cache fcc = {0};

    if (!SIGNALFX_TRACING_G(original_context).execute_data || !EX(prev_execute_data)) {
        zend_throw_exception_ex(spl_ce_LogicException, 0 TSRMLS_CC,
                                "Cannot use dd_trace_forward_call() outside of a tracing closure");
        return;
    }

    // Jump out of any include files
    zend_execute_data *prev_ex = EX(prev_execute_data);
    while (!prev_ex->func->common.function_name) {
        prev_ex = prev_ex->prev_execute_data;
    }
    zend_string *callback_name = !prev_ex ? NULL : prev_ex->func->common.function_name;

    if (!callback_name || !zend_string_equals_literal(callback_name, DDTRACE_CALLBACK_NAME)) {
        zend_throw_exception_ex(spl_ce_LogicException, 0 TSRMLS_CC,
                                "Cannot use dd_trace_forward_call() outside of a tracing closure");
        return;
    }

    ZVAL_STR_COPY(&fname, SIGNALFX_TRACING_G(original_context).execute_data->func->common.function_name);

    fci.size = sizeof(fci);
    fci.function_name = fname;
    fci.retval = &retval;
    fci.param_count = ZEND_CALL_NUM_ARGS(SIGNALFX_TRACING_G(original_context).execute_data);
    fci.params = ZEND_CALL_ARG(SIGNALFX_TRACING_G(original_context).execute_data, 1);
    fci.object = SIGNALFX_TRACING_G(original_context).this;
    fci.no_separation = 1;

#if PHP_VERSION_ID < 70300
    fcc.initialized = 1;
#endif
    fcc.function_handler = SIGNALFX_TRACING_G(original_context).execute_data->func;
    fcc.calling_scope = SIGNALFX_TRACING_G(original_context).calling_ce;
    fcc.called_scope = fci.object ? fci.object->ce : SIGNALFX_TRACING_G(original_context).fbc->common.scope;
    fcc.object = fci.object;

    if (zend_call_function(&fci, &fcc) == SUCCESS && Z_TYPE(retval) != IS_UNDEF) {
#if PHP_VERSION_ID >= 70100
        if (Z_ISREF(retval)) {
            zend_unwrap_reference(&retval);
        }
#endif
        ZVAL_COPY_VALUE(return_value, &retval);
    }

    zval_ptr_dtor(&fname);
}
#endif  // PHP_VERSION_ID >= 70000
