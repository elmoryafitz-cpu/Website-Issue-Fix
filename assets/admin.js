jQuery(function ($) {
    'use strict';
    var running = false, busy = false, page = 0, timer = null, latest = null;
    var active = ['detecting', 'posts', 'terms', 'scan', 'summarize', 'fixing'];
    function message(text) { $('#gscsf-message').text(text || ''); }
    function request(verb, extra) {
        if (extra instanceof FormData) {
            extra.append('action', 'gscsf_action'); extra.append('nonce', gscsf.nonce); extra.append('verb', verb);
            return $.ajax({url: gscsf.url, method: 'POST', dataType: 'json', timeout: 60000, data: extra, processData: false, contentType: false});
        }
        return $.ajax({url: gscsf.url, method: 'POST', dataType: 'json', timeout: 60000,
            data: $.extend({action: 'gscsf_action', nonce: gscsf.nonce, verb: verb, page: page}, extra || {})});
    }
    function link(url) {
        var element = $('<span>').text(url);
        try {
            var parsed = new URL(url);
            if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
                element = $('<a>', {href: parsed.href, target: '_blank', rel: 'noopener noreferrer'}).text(url);
            }
        } catch (ignore) { /* Render invalid URLs as text. */ }
        return element;
    }
    function render(data) {
        latest = data;
        var c = data.counts, phase = data.job.phase, isActive = active.indexOf(phase) !== -1;
        var profile = $('#gscsf-profile').empty();
        if (data.profile) {
            $('<h3>').text(data.profile.type).appendTo(profile);
            $('<p>').text('Detected: ' + data.profile.detected_at + ' | ' + (data.profile.public ? 'Search engine visibility enabled' : 'WordPress discourages indexing')).appendTo(profile);
            $('<p>').text('Commerce: ' + (data.profile.commerce.join(', ') || 'No supported commerce software detected') + ' | SEO providers: ' + (data.profile.seo.join(', ') || 'None detected')).appendTo(profile);
            $('<p>').text(data.profile.content_types.map(function (type) { return type.label + ': ' + type.published; }).join(' | ')).appendTo(profile);
        } else { $('<p>').text('Website type will be detected before issue scanning starts.').appendTo(profile); }
        $('#gscsf-detect').prop('disabled', isActive || busy);
        var coverage = $('#gscsf-coverage').empty();
        (data.coverage || []).forEach(function (item) {
            $('<h3>').text(item.name + ' — ' + item.status).appendTo(coverage);
            $('<p>').text(item.detail).appendTo(coverage);
        });
        var labels = {idle: 'Ready to scan.', posts: 'Discovering published content…', terms: 'Discovering taxonomy archives…', scan: 'Scanning URLs…', fixing: 'Applying and verifying repairs…', complete: 'Scan completed. Review findings below.', cancelled: 'Operation cancelled; coverage is incomplete.', paused: 'Operation paused after an error. Review the message, then cancel and start a new scan.'};
        $('#gscsf-status').text((labels[phase] || phase) + (data.job.started ? ' Started: ' + data.job.started : ''));
        if (phase === 'detecting') { $('#gscsf-status').text('Identifying website type, commerce software and public content…'); }
        if (phase === 'summarize') { $('#gscsf-status').text('Checking redirect chains, sitemap membership and observed references…'); }
        $('#gscsf-progress').val(c.total ? Math.round(c.done / c.total * 100) : 0);
        $('#gscsf-counts').text(c.done + ' / ' + c.total + ' discovered URLs checked · ' + c.findings + ' findings (including information) · ' + c.fixable + ' automatic repair candidates · ' + c.fixed + ' verified fixes in this run');
        $('#gscsf-start').prop('disabled', isActive || busy);
        $('#gscsf-resume').prop('hidden', !isActive || running).prop('disabled', busy);
        $('#gscsf-cancel').prop('hidden', !isActive && phase !== 'paused').prop('disabled', busy);
        $('#gscsf-fix').prop('hidden', phase !== 'complete').prop('disabled', busy || !c.fixable)
            .text(c.fixable ? 'Auto Fix Solvable Issues (' + c.fixable + ')' : 'No automatic repairs available');
        $('#gscsf-settings :input').prop('disabled', isActive || busy);
        $('#gscsf-import :input').prop('disabled', isActive || busy);
        var imported = data.import || {};
        var summary = $('#gscsf-import-summary').empty();
        if (imported.files) {
            $('<p>').text(imported.files + ' distinct reports · ' + imported.duplicates + ' duplicate files skipped · ' + imported.url_count + ' matching scan URLs · ' + imported.foreign_rows + ' off-site rows skipped · ' + imported.utility_urls + ' utility URL occurrences excluded. Import date: ' + imported.imported_at).appendTo(summary);
            (imported.warnings || []).forEach(function (warning) { $('<p>').text(warning).appendTo(summary); });
        } else { $('<p>').text('No audit imported.').appendTo(summary); }
        if (data.resource_limit) { message('The additional-resource limit was reached. Resource coverage is partial; WordPress content inventory still runs.'); }
        if (data.job.notice) { message(data.job.notice); }
        var results = $('#gscsf-results').empty();
        if (!data.rows.length) {
            $('<p>').text(phase === 'complete' ? 'No findings from the checks performed. This does not certify Google indexing or every possible website issue.' : 'Results appear as URLs are checked.').appendTo(results);
        }
        data.rows.forEach(function (row) {
            var filter = $('#gscsf-filter').val();
            var issues = row.issues.filter(function (issue) {
                return filter === 'all' || (filter === 'fixable' && issue.fixable) || (filter === 'review' && !issue.fixable) || (filter === 'error' && issue.severity === 'error');
            });
            if (!issues.length) { return; }
            var article = $('<article>', {'class': 'gscsf-result'}).appendTo(results);
            $('<h3>').append(link(row.url)).appendTo(article);
            $('<p>', {'class': 'description'}).text(row.kind + ' · Checked: ' + row.checked_at + (row.fix_state ? ' · Repair: ' + row.fix_state : '')).appendTo(article);
            if (row.refs && row.refs.length) {
                var refs = $('<p>').text('Observed references (up to 5): ').appendTo(article);
                row.refs.forEach(function (url) { refs.append(link(url)).append(document.createTextNode(' ')); });
            }
            var list = $('<ul>').appendTo(article);
            issues.forEach(function (issue) {
                var item = $('<li>').appendTo(list);
                $('<strong>').text('[' + issue.source + ' / ' + issue.severity + '] ' + (issue.fixable ? 'Auto-fixable: ' : 'Review: ')).appendTo(item);
                $('<span>').text(issue.message).appendTo(item);
            });
        });
        page = data.page;
        if (data.rows.length && !results.children().length) { $('<p>').text('No matching findings on this report page.').appendTo(results); }
        $('#gscsf-prev').prop('hidden', page === 0).prop('disabled', busy);
        $('#gscsf-next').prop('hidden', (page + 1) * 50 >= data.report_rows).prop('disabled', busy);
        var history = $('#gscsf-history').empty();
        if (!data.logs.length) { $('<p>').text('No verified repairs yet.').appendTo(history); }
        data.logs.forEach(function (entry) {
            var row = $('<p>').append(link(entry.url)).appendTo(history);
            var repaired = [];
            try { repaired = JSON.parse(entry.codes); } catch (ignore) { /* Older log entry. */ }
            $('<span>').text(' | ' + repaired.join(', ')).appendTo(row);
            $('<span>').text(' · ' + entry.created_at + ' · ' + entry.state + ' ').appendTo(row);
            if (entry.state === 'verified') {
                $('<button>', {type: 'button', 'class': 'button'}).text('Undo').prop('disabled', isActive || busy).on('click', function () { perform('undo', {id: entry.id}); }).appendTo(row);
            }
        });
    }
    function failure(xhr) {
        var error = xhr.responseJSON && xhr.responseJSON.data;
        message(typeof error === 'string' ? error : 'Request failed. Check the connection and resume; completed work is saved. Refresh this page if your administrator session expired.');
    }
    function pump() {
        if (!running || busy) { return; }
        busy = true;
        request('tick').done(function (response) {
            if (!response.success) { running = false; message(response.data); return; }
            render(response.data);
            running = active.indexOf(response.data.job.phase) !== -1;
        }).fail(function (xhr) {
            if (xhr.status !== 409) { running = false; failure(xhr); }
        }).always(function () {
            busy = false;
            if (latest) { render(latest); }
            if (running) { timer = setTimeout(pump, 1200); }
        });
    }
    function perform(verb, extra) {
        if (busy) { return; }
        clearTimeout(timer);
        busy = true;
        message('');
        if (latest) { render(latest); }
        request(verb, extra).done(function (response) {
            if (!response.success) { message(response.data); return; }
            render(response.data);
            running = active.indexOf(response.data.job.phase) !== -1;
            if (verb === 'save') { message('Settings saved.'); }
            if (verb === 'import') { message('Audit imported. Click Scan Website to verify the imported URLs against current responses.'); }
        }).fail(function (xhr) { running = false; failure(xhr); }).always(function () {
            busy = false;
            if (latest) { render(latest); }
            if (running) { timer = setTimeout(pump, 1200); }
        });
    }
    $('#gscsf-start').on('click', function () { page = 0; perform('start'); });
    $('#gscsf-detect').on('click', function () { perform('detect'); });
    $('#gscsf-filter').on('change', function () { if (latest) { render(latest); } });
    $('#gscsf-fix').on('click', function () { page = 0; perform('fix'); });
    $('#gscsf-cancel').on('click', function () { running = false; perform('cancel'); });
    $('#gscsf-resume').on('click', function () { running = true; pump(); });
    $('#gscsf-next').on('click', function () { page++; perform('state'); });
    $('#gscsf-prev').on('click', function () { page = Math.max(0, page - 1); perform('state'); });
    $('#gscsf-settings').on('submit', function (e) {
        e.preventDefault();
        var data = {daily: 0, auto: 0, google: 0, external: 0};
        $(this).serializeArray().forEach(function (field) { data[field.name] = field.value; });
        perform('save', data);
    });
    $('#gscsf-import').on('submit', function (e) { e.preventDefault(); perform('import', new FormData(this)); });
    $('#gscsf-clear-import').on('click', function () { perform('clear_import'); });
    request('state').done(function (response) { if (response.success) { render(response.data); } else { message(response.data); } }).fail(failure);
});
