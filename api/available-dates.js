const fs = require('fs');
const path = require('path');

function getSupabaseConfig() {
    let url = process.env.SUPABASE_URL || '';
    // Supabase REST API lives at https://<ref>.supabase.co — strip pooler/port/db parts
    // If the user pasted a Postgres connection string, it can't be used for PostgREST.
    if (url && !url.includes('supabase.co') && !url.includes('/rest/v1')) {
        url = '';
    }
    return {
        url,
        anonKey: process.env.SUPABASE_ANON_KEY || '',
        serviceRoleKey: process.env.SUPABASE_SERVICE_ROLE_KEY || '',
    };
}

function fallbackPath() {
    return path.join(__dirname, 'demo_available_dates.json');
}

function loadFallback() {
    try {
        const filePath = fallbackPath();
        if (!fs.existsSync(filePath)) return [];
        const data = JSON.parse(fs.readFileSync(filePath, 'utf-8'));
        return Array.isArray(data) ? data : [];
    } catch (err) {
        console.warn('[available-dates] loadFallback skipped:', err.code || err.message);
        return [];
    }
}

function saveFallback(dates) {
    // On read-only filesystems (e.g. Vercel serverless) this will throw EROFS.
    // Treat it as a soft failure so the API still returns success — Supabase
    // is the source of truth in production, and local dev still works.
    try {
        fs.writeFileSync(fallbackPath(), JSON.stringify(dates, null, 2));
        return true;
    } catch (err) {
        console.warn('[available-dates] saveFallback skipped:', err.code || err.message);
        return false;
    }
}

async function supabaseRequest(method, apiPath, payload = {}, query = {}) {
    const cfg = getSupabaseConfig();
    if (!cfg.url || !cfg.serviceRoleKey) {
        return { ok: false, status: 503, message: 'Supabase not configured', fallback: true };
    }

    let url = cfg.url.replace(/\/+$/, '') + '/rest/v1/' + apiPath.replace(/^\/+/, '');
    if (Object.keys(query).length > 0) {
        url += '?' + new URLSearchParams(query).toString();
    }

    const headers = {
        'Content-Type': 'application/json',
        'apikey': cfg.anonKey,
        'Authorization': 'Bearer ' + cfg.serviceRoleKey,
        'Prefer': 'return=representation',
    };

    const fetchOptions = { method, headers };
    if (method !== 'GET' && payload && Object.keys(payload).length > 0) {
        fetchOptions.body = JSON.stringify(payload);
    }

    try {
        const response = await fetch(url, fetchOptions);
        const text = await response.text();
        let body;
        try { body = JSON.parse(text); } catch { body = text; }
        return { ok: response.ok, status: response.status, body, raw: text };
    } catch (err) {
        return { ok: false, status: 500, message: 'Fetch error: ' + err.message };
    }
}

function newId() {
    return 'demo-ad-' + require('crypto').randomBytes(3).toString('hex');
}

module.exports = async (req, res) => {
    res.setHeader('Content-Type', 'application/json');
    const method = req.method;

    // GET — fetch available dates in a range
    if (method === 'GET') {
        const url = new URL(req.url, 'http://localhost');
        const from = url.searchParams.get('from');
        const to = url.searchParams.get('to');

        const query = { select: 'visit_date,is_available,max_bookings', order: 'visit_date.asc' };
        if (from && to) {
            query.visit_date = 'and(gte.' + from + ',lte.' + to + ')';
        } else if (from) {
            query.visit_date = 'gte.' + from;
        } else if (to) {
            query.visit_date = 'lte.' + to;
        }

        const result = await supabaseRequest('GET', 'farm_available_dates', {}, query);

        if (result.ok && Array.isArray(result.body)) {
            return res.status(200).json({ ok: true, body: result.body });
        }

        // Supabase failed (not configured, wrong URL, or 4xx/5xx) — fall back to demo JSON
        // so the UI keeps working even before the project is fully wired to Supabase.
        let dates = loadFallback();
        if (from) dates = dates.filter(d => d.visit_date >= from);
        if (to) dates = dates.filter(d => d.visit_date <= to);
        return res.status(200).json({ ok: true, body: dates, fallback: true });
    }

    // POST — toggle single date, bulk update, or generate
    if (method === 'POST') {
        // Use Express-parsed body if available, otherwise parse manually
        const input = (req.body && Object.keys(req.body).length > 0)
            ? req.body
            : (() => {
                const chunks = [];
                for (const c of req) chunks.push(c);
                if (chunks.length === 0) return {};
                try { return JSON.parse(Buffer.concat(chunks).toString()) || {}; }
                catch { return {}; }
            })();

        // Generate dates from day-of-week rules
        if (input.generate && Array.isArray(input.generate.days)) {
            const { days, from, to, is_available = true } = input.generate;
            if (!from || !to) {
                return res.status(422).json({ ok: false, message: 'from and to dates are required.' });
            }
            const startDate = new Date(from);
            const endDate = new Date(to);
            const datesToInsert = [];
            for (let d = new Date(startDate); d <= endDate; d.setDate(d.getDate() + 1)) {
                if (days.includes(d.getDay())) {
                    datesToInsert.push({
                        visit_date: d.toISOString().split('T')[0],
                        is_available,
                        max_bookings: 12,
                    });
                }
            }

            if (datesToInsert.length === 0) {
                return res.status(200).json({ ok: true, message: 'No dates matched the selected days.', body: [] });
            }

            const result = await supabaseRequest('POST', 'farm_available_dates', datesToInsert);
            if (!result.ok) {
                const existing = loadFallback();
                for (const entry of datesToInsert) {
                    const idx = existing.findIndex(e => e.visit_date === entry.visit_date);
                    if (idx >= 0) {
                        existing[idx].is_available = entry.is_available;
                    } else {
                        existing.push({ id: newId(), ...entry, created_at: new Date().toISOString() });
                    }
                }
                saveFallback(existing);
                return res.status(200).json({ ok: true, message: datesToInsert.length + ' dates generated.', body: datesToInsert, fallback: true });
            }

            return res.status(200).json({ ok: true, message: datesToInsert.length + ' dates generated.', body: result.body || datesToInsert });
        }

        // Bulk update
        if (input.bulk && Array.isArray(input.bulk)) {
            const results = [];
            let supabaseOk = true;
            for (const entry of input.bulk) {
                if (!entry.visit_date) continue;
                const payload = {
                    visit_date: entry.visit_date,
                    is_available: entry.is_available !== undefined ? entry.is_available : true,
                    max_bookings: entry.max_bookings || 12,
                };

                const existing = await supabaseRequest('GET', 'farm_available_dates', {}, { select: 'id', visit_date: 'eq.' + entry.visit_date });
                if (existing.ok && Array.isArray(existing.body) && existing.body.length > 0) {
                    const r = await supabaseRequest('PATCH', 'farm_available_dates?visit_date=eq.' + entry.visit_date, { is_available: payload.is_available });
                    if (!r.ok) supabaseOk = false;
                } else {
                    const r = await supabaseRequest('POST', 'farm_available_dates', payload);
                    if (!r.ok) supabaseOk = false;
                }
                results.push(payload);
            }

            // Also write to fallback (local dev only; silently skipped on read-only fs)
            const fallback = loadFallback();
            for (const entry of input.bulk) {
                if (!entry.visit_date) continue;
                const idx = fallback.findIndex(e => e.visit_date === entry.visit_date);
                const record = { visit_date: entry.visit_date, is_available: entry.is_available !== false, max_bookings: entry.max_bookings || 12 };
                if (idx >= 0) {
                    fallback[idx].is_available = record.is_available;
                } else {
                    fallback.push({ id: newId(), ...record, created_at: new Date().toISOString() });
                }
            }
            saveFallback(fallback);

            return res.status(200).json({
                ok: true,
                message: results.length + ' dates updated.',
                body: results,
                persisted: supabaseOk,
                warning: supabaseOk ? null : 'Changes will not persist between requests. Connect a working Supabase project to enable real persistence.'
            });
        }

        // Single toggle
        if (!input.visit_date) {
            return res.status(422).json({ ok: false, message: 'visit_date is required.' });
        }

        const singlePayload = {
            visit_date: input.visit_date,
            is_available: input.is_available !== undefined ? input.is_available : true,
            max_bookings: input.max_bookings || 12,
        };

        const checkExisting = await supabaseRequest('GET', 'farm_available_dates', {}, { select: 'id', visit_date: 'eq.' + input.visit_date });
        let result;
        if (checkExisting.ok && Array.isArray(checkExisting.body) && checkExisting.body.length > 0) {
            result = await supabaseRequest('PATCH', 'farm_available_dates?visit_date=eq.' + input.visit_date, { is_available: singlePayload.is_available });
        } else {
            result = await supabaseRequest('POST', 'farm_available_dates', singlePayload);
        }

        // Also write to fallback (local dev only; silently skipped on read-only fs)
        const fallback = loadFallback();
        const idx = fallback.findIndex(e => e.visit_date === input.visit_date);
        if (idx >= 0) {
            fallback[idx].is_available = singlePayload.is_available;
        } else {
            fallback.push({ id: newId(), ...singlePayload, created_at: new Date().toISOString() });
        }
        saveFallback(fallback);

        return res.status(200).json({
            ok: true,
            message: 'Date toggled.',
            body: singlePayload,
            persisted: !!result.ok,
            warning: result.ok ? null : 'Changes will not persist between requests. Connect a working Supabase project to enable real persistence.'
        });
    }

    return res.status(405).json({ ok: false, message: 'Method not allowed.' });
};
