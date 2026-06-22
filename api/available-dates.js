const fs = require('fs');
const path = require('path');

function getSupabaseConfig() {
    return {
        url: process.env.SUPABASE_URL || '',
        anonKey: process.env.SUPABASE_ANON_KEY || '',
        serviceRoleKey: process.env.SUPABASE_SERVICE_ROLE_KEY || '',
    };
}

function fallbackPath() {
    return path.join(__dirname, 'demo_available_dates.json');
}

function loadFallback() {
    const filePath = fallbackPath();
    if (!fs.existsSync(filePath)) return [];
    try {
        const data = JSON.parse(fs.readFileSync(filePath, 'utf-8'));
        return Array.isArray(data) ? data : [];
    } catch {
        return [];
    }
}

function saveFallback(dates) {
    fs.writeFileSync(fallbackPath(), JSON.stringify(dates, null, 2));
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

        if (!result.ok && result.fallback) {
            let dates = loadFallback();
            if (from) dates = dates.filter(d => d.visit_date >= from);
            if (to) dates = dates.filter(d => d.visit_date <= to);
            return res.status(200).json({ ok: true, body: dates });
        }

        if (result.ok && Array.isArray(result.body)) {
            return res.status(200).json({ ok: true, body: result.body });
        }

        return res.status(result.status || 500).json(result);
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
            if (!result.ok && result.fallback) {
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
                return res.status(200).json({ ok: true, message: datesToInsert.length + ' dates generated.', body: datesToInsert });
            }

            return res.status(200).json({ ok: true, message: datesToInsert.length + ' dates generated.', body: result.body || datesToInsert });
        }

        // Bulk update
        if (input.bulk && Array.isArray(input.bulk)) {
            const results = [];
            for (const entry of input.bulk) {
                if (!entry.visit_date) continue;
                const payload = {
                    visit_date: entry.visit_date,
                    is_available: entry.is_available !== undefined ? entry.is_available : true,
                    max_bookings: entry.max_bookings || 12,
                };

                const existing = await supabaseRequest('GET', 'farm_available_dates', {}, { select: 'id', visit_date: 'eq.' + entry.visit_date });
                if (existing.ok && Array.isArray(existing.body) && existing.body.length > 0) {
                    await supabaseRequest('PATCH', 'farm_available_dates?visit_date=eq.' + entry.visit_date, { is_available: payload.is_available });
                } else {
                    await supabaseRequest('POST', 'farm_available_dates', payload);
                }
                results.push(payload);
            }

            // Always also write to fallback so the local dev works
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

            return res.status(200).json({ ok: true, message: results.length + ' dates updated.', body: results });
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

        // Always also write to fallback so local dev works
        const fallback = loadFallback();
        const idx = fallback.findIndex(e => e.visit_date === input.visit_date);
        if (idx >= 0) {
            fallback[idx].is_available = singlePayload.is_available;
        } else {
            fallback.push({ id: newId(), ...singlePayload, created_at: new Date().toISOString() });
        }
        saveFallback(fallback);

        return res.status(200).json({ ok: true, message: 'Date toggled.', body: singlePayload });
    }

    return res.status(405).json({ ok: false, message: 'Method not allowed.' });
};
