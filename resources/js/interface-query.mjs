export function queryUrl(base, values = {}) {
    const url = new URL(base);
    url.search = '';
    const append = (key, value) => {
        if (value === null || value === undefined || value === '') return;
        if (Array.isArray(value)) value.forEach(item => append(key + '[]', item));
        else if (typeof value === 'object') Object.entries(value).forEach(([name,item]) => append(`${key}[${name}]`,item));
        else url.searchParams.append(key, String(value));
    };
    Object.entries(values).forEach(([key,value]) => append(key,value));
    return url.href;
}
export function reportFilters(url) {
    const params = new URL(url, 'http://perysee.local').searchParams;
    const values = Object.fromEntries(['value','source_group','provider','dimension','version','start_type','start_value','compare'].filter(key => params.has(key)).map(key => [key,params.get(key)]));
    const filters = {};
    for (const [key,value] of params) {
        const match = key.match(/^filters\[([a-z_]+)\]\[(?:\d*)\]$/);
        if (match) (filters[match[1]] ??= []).push(value);
    }
    if (Object.keys(filters).length) values.filters = filters;
    return values;
}
export function selectFilter(url, dimension, value) {
    const current = reportFilters(url);
    delete current.value;
    return {...current, filters:{...current.filters,[dimension]:value == null ? [] : [value]}};
}
