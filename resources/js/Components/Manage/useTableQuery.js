import { router } from '@inertiajs/vue3';

/**
 * All list-page state lives in the query string: search, sort, dir, page, per_page and
 * filter[...]. That keeps every view linkable and shareable, and means a poll can reload
 * just the data props without losing where the operator was.
 */
export function useTableQuery(only = ['table']) {
  const current = () => Object.fromEntries(new URLSearchParams(window.location.search));

  const visit = (params, { resetPage = true } = {}) => {
    const query = { ...current(), ...params };

    if (resetPage) {
      delete query.page;
    }

    for (const [key, value] of Object.entries(query)) {
      if (value === '' || value === null || value === undefined) {
        delete query[key];
      }
    }

    router.get(window.location.pathname, query, {
      only,
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const toggleSort = (column, sort) => {
    if (!column.sortable) {
      return;
    }

    const dir = sort?.key === column.key && sort?.dir === 'asc' ? 'desc' : 'asc';

    visit({ sort: column.key, dir });
  };

  const setSearch = (value) => visit({ search: value });

  const setFilter = (key, value) => {
    const params = {};

    if (Array.isArray(value)) {
      // Drop any previous indexed entries for this filter before writing the new set.
      for (const existing of Object.keys(current())) {
        if (existing.startsWith(`filter[${key}]`)) {
          params[existing] = '';
        }
      }

      value.forEach((item, index) => {
        params[`filter[${key}][${index}]`] = item;
      });

      if (value.length === 0) {
        params[`filter[${key}][0]`] = '';
      }

      return visit(params);
    }

    return visit({ [`filter[${key}]`]: typeof value === 'boolean' ? (value ? '1' : '0') : value });
  };

  const setPage = (page) => visit({ page }, { resetPage: false });

  const setPerPage = (perPage) => visit({ per_page: perPage });

  return { visit, toggleSort, setSearch, setFilter, setPage, setPerPage };
}
