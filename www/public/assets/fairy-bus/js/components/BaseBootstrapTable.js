/**
 * Copyright since 2026 Massimiliano Palermo
 * @license AFL-3.0
 */

import FetchHelper from '../core/FetchHelper.js';

export default class BaseBootstrapTable {
    constructor(tableSelector, options = {}) {
        this.$table = window.$(tableSelector);
        this.url = options.url || null;
        this.columns = options.columns || [];
        this.sidePagination = options.sidePagination || 'server';
        this.method = options.method || 'get';
        this.queryParams = options.queryParams || (() => ({}));
        this.responseHandler = options.responseHandler || (res => res);
        this.abortController = null;

        this.init();
    }

    init() {
        if (!this.$table.length) {
            return;
        }

        this.destroy();

        this.$table.bootstrapTable('destroy').bootstrapTable({
            url: this.url,
            method: this.method,
            sidePagination: this.sidePagination,
            queryParams: this.queryParams,
            responseHandler: this.responseHandler,
            columns: this.columns,
            pagination: true,
            pageSize: 10,
            pageList: [10, 25, 50, 100],
            search: true,
            showRefresh: true,
            iconsPrefix: 'fa',
            ajaxOptions: {
                beforeSend: () => {
                    this.abortController = new AbortController();
                    return { signal: this.abortController.signal };
                },
            },
        });
    }

    refresh(params = {}) {
        this.$table.bootstrapTable('refresh', { query: params });
    }

    destroy() {
        if (this.abortController) {
            this.abortController.abort();
            this.abortController = null;
        }
        if (this.$table && this.$table.bootstrapTable) {
            this.$table.bootstrapTable('destroy');
        }
    }
}
