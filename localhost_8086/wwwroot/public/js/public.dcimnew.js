(function (win, $) {
    if (!$) {
        return;
    }

    function parseResponse(res) {
        if (typeof res === "string") {
            try {
                return JSON.parse(res);
            } catch (e) {
                return {};
            }
        }
        return res || {};
    }

    function normalizePath(path) {
        var base = (win.ajaxUrl || "").replace(/\/+$/, "");
        var endpoint = (path || "").replace(/^\/+/, "");
        return base + "/" + endpoint;
    }

    function normalizeFilterPath(path) {
        var p = (path || "").replace(/\/+$/, "");
        if (/\/filter$/i.test(p)) {
            return p;
        }
        return p + "/filter";
    }

    function buildHeaders() {
        var t = localStorage.getItem("token") || "";
        var headers = {};
        if (t) {
            headers.Auth = t;
            headers.Authorization = 'Bearer ' + t;
        }
        return headers;
    }

    function isTotalDataListResponse(res) {
        return !!(res && typeof res.total !== "undefined" && Array.isArray(res.data));
    }

    function handleApiError(res, fail) {
        if (res && (res.code === 100 || res.success === true || isTotalDataListResponse(res))) {
            return false;
        }
        if (res && res.code === 300 && typeof win.tologin === "function") {
            win.tologin();
            return true;
        }
        if (fail) {
            fail(res);
            return true;
        }
        if (win.layer && res && res.msg) {
            layer.msg(res.msg, { icon: 5, time: 1200 });
        }
        return true;
    }

    function request(method, path, payload, done, fail) {
        var options = {
            type: method,
            url: normalizePath(path),
            headers: buildHeaders(),
            success: function (res) {
                var data = parseResponse(res);
                if (!handleApiError(data, fail) && done) {
                    done(data);
                }
            },
            error: function (xhr) {
                if (fail) {
                    fail(xhr);
                    return;
                }
                if (win.layer) {
                    layer.msg("请求失败", { icon: 5, time: 1200 });
                }
            }
        };

        if (method === "GET") {
            options.data = payload || {};
        } else {
            options.data = JSON.stringify(payload || {});
            options.contentType = "application/json; charset=utf-8";
            options.processData = false;
        }

        $.ajax(options);
    }

    function calcOffset(limit, pageNo) {
        var l = parseInt(limit, 10);
        var p = parseInt(pageNo, 10);
        if (!l || l < 1) l = 15;
        if (!p || p < 1) p = 1;
        return {
            limit: l,
            offset: (p - 1) * l
        };
    }

    win.dcimNewApi = {
        get: function (path, params, done, fail) {
            request("GET", path, params, done, fail);
        },
        post: function (path, body, done, fail) {
            request("POST", path, body, done, fail);
        },
        filter: function (path, params, limit, pageNo, done, fail) {
            var pageInfo = calcOffset(limit, pageNo);
            request("GET", normalizeFilterPath(path), {
                limit: pageInfo.limit,
                offset: pageInfo.offset,
                params: JSON.stringify(params || {})
            }, done, fail);
        }
    };
})(window, window.jQuery);

